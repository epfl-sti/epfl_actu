<?php

/**
 * A set of abstract base classes for Actu, Memento and more.
 */

namespace EPFL\WS\Base;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

use \WP_Query;
use \Exception;

require_once(dirname(__FILE__) . "/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

require_once(dirname(__FILE__) . "/batch.inc");
use function \EPFL\WS\run_every;
use \EPFL\WS\BatchTask;

require_once(dirname(__FILE__) . "/image-size.inc");
use function \EPFL\WS\get_image_size;

require_once(dirname(__FILE__) . "/auto-fields.inc");
use \EPFL\WS\AutoFields;
use \EPFL\WS\AutoFieldsController;

require_once(dirname(__FILE__) . "/activation.inc");
use function \EPFL\WS\plugin_activation_hook;
use function \EPFL\WS\plugin_deactivation_hook;

function _debug ($msg)
{
    // error_log($msg);
}

class UnicityException extends Exception {}

/**
 * A helper class to prepare and invoke custom WP_Query objects.
 */
class _WPQueryBuilder
{
    function __construct ($query)
    {
        if (is_string($query)) {
            $query = array('post_type' => $query);
        }
        $this->query = $query;
    }

    function by_meta ($meta_array)
    {
        if (! $this->query["meta_query"]) {
            $this->query["meta_query"] = array('relation' => 'AND');
        }

        foreach ($meta_array as $k => $v) {
            array_push($this->query["meta_query"], array(
                'key'     => $k,
                'value'   => $v,
                'compare' => '='
            ));
        }

        return $this;
    }

    function wp_query ()
    {
        if (! $this->query) {
            throw new Exception("Can only call one of ->wp_query, ->result or ->results");
        }
        $retval = new WP_Query($this->query);
        unset($this->query);
        return $retval;
    }

    function result ()
    {
        $results = $this->wp_query()->get_posts();
        if (count($results) > 1) {
            throw new UnicityException(sprintf(
                "%s returned %d results (%s",
                $this->moniker(), count($results),
                var_export(array_map(function($post) { return $post->ID; }, $results),
                          true)));
        }
        return $results[0];
    }

    function results ()
    {
        if (! $query["posts_per_page"]) {
            $query["posts_per_page"] = -1;
        }
        return $this->wp_query()->get_posts();
    }

    function moniker ($set_it = null) {
        if ($set_it !== null) {
            $this->moniker = $set_it;
            return $this;    // Chainable
        } elseif ($this->moniker) {
            return $this->moniker;
        } else {
            return "<" . var_export($this->query, true) . ">";
        }
    }
}

/**
 * Abstract base classes for taxonomies whose terms have an URL as metadata.
 *
 * A "taxonomy" is a complicated word for a way to organize WordPress
 * posts together. Post types that are automatically scraped from some kind
 * of API or Web service, belong to a taxonomy which is represented by
 * a subclass of ScrapedTaxonomy (e.g. ActuStream, CourseTaxonomy etc.)
 *
 * Instances of these subclasses represent one so-called "term", that
 * is, one API channel or feed with its initial URL.
 */
abstract class StreamTaxonomy
{
    /**
     * @return The object class for WP posts this APIChannelTaxonomy applies to.
     */
    static abstract function get_post_class ();

    /**
     * @return The taxonomy slug (a unique keyword) used to
     *         distinguish the terms of this taxonomy from all the
     *         other ones in the WordPress database
     */
    static abstract function get_taxonomy_slug ();

    /**
     * @return A slug (unique keyword) used to associate the URL
     *         metadata to objects of this class ("terms") in the
     *         WordPress database
     */
    static abstract function get_term_meta_slug ();

    /**
     * Actually fetch data from @link get_url and create / update
     * as many instances of @get_post_class as needed
     */
    abstract function sync ();

    function __construct($term_or_term_id)
    {
        if (is_object($term_or_term_id)) {
            $this->ID = $term_or_term_id->term_id;
        } else {
            $this->ID = $term_or_term_id;
        }
    }

    static function get_all ()
    {
        $thisclass = get_called_class();
        $all = array();
        foreach (get_terms(array(
            'taxonomy'   => $thisclass::get_taxonomy_slug(),
            'hide_empty' => false
        )) as $wp_term) {
            if (is_wp_error($wp_term)) {
                throw new Exception($wp_term->get_error_message());
            } elseif (array_key_exists("invalid_taxonomy", $wp_term)) {
                throw new Exception(sprintf(
                    "get_terms() says %s is an invalid taxonomy",
                    $thisclass::get_taxonomy_slug()));
            }
            array_push($all, new $thisclass($wp_term));
        }
        return $all;
    }

    function get_url ()
    {
        if (! $this->url) {
            $this->url = get_term_meta( $this->ID, $this->get_term_meta_slug(), true );
        }
        return $this->url;
    }

    function set_url ($url)
    {
        $this->url = $url;
        delete_term_meta($this->ID, $this->get_term_meta_slug());
        add_term_meta($this->ID, $this->get_term_meta_slug(), $url);
    }

    function as_wp_term ()
    {
        return \WP_Term::get_instance($this->ID, $this->get_taxonomy_slug());
    }

    /**
     * Mark in the database that $post was found by
     * fetching from this stream object.
     *
     * This is materialized by a relationship in the
     * wp_term_relationships SQL table, using the @link
     * wp_set_post_terms API.
     */
    function set_ownership($post)
    {
        $terms = wp_get_post_terms(
            $post->ID, $this->get_taxonomy_slug(),
            array('fields' => 'ids'));
        if (! in_array($this->ID, $terms)) {
            wp_set_post_terms($post->ID, array($this->ID),
                              $this->get_taxonomy_slug(),
                              true);  // Append
        }
    }
}

/**
 * Subclass to StreamTaxonomy for Actu and Memento streams
 *
 * This class just mixes in a @link sync method that uses ActuAPIClient.
 */
abstract class APIChannelTaxonomy extends StreamTaxonomy
{
    function sync ()
    {
        require_once (dirname(__FILE__) . "/ActuAPI.inc");
        $client = new \EPFL\WS\Actu\ActuAPIClient($this);
        foreach ($client->fetch() as $api_result) {
            $post_class = $this->get_post_class();
            $epfl_post = $post_class::sync($api_result);
            $this->set_ownership($epfl_post);
        }
    }
}

/**
 * Configuration UI and WP callbacks for a StreamTaxonomy class.
 *
 * A taxonomy is pretty much an end-user-invisible concept so much of the
 * responsibility of this class is towards wp-admin. This class has
 * no instances.
 */
abstract class StreamTaxonomyController
{
    /**
     * @return The @link APIChannelTaxonomy subclass this controller serves.
     */
    abstract static function get_taxonomy_class ();

    /**
     * @return An URL to show as an example in the "URL" field of a new
     * StreamTaxonomy instance being created in wp-admin
     */
    abstract static function get_placeholder_api_url ();

    /**
     * Get the labels to display in various places in the UI.
     *
     * @return An associative array whose keys are i18n-neutral
     *         keywords and whose values are translation strings. This
     *         array gets passed as-is as the 'labels' value to
     *         WordPress' @link register_taxonomy, and therefore ought
     *         to contain like-named keys. Additionally the following
     *         keys are used by StreamTaxonomyController directly:
     *
     * - url_legend: A short label to display next to the
     *               channel API URL field
     *
     * - url_legend_long: A longer explanatory text to display next to
     *               the channel API URL field
     *
     */
    abstract static function get_human_labels ();

    static function hook ()
    {
        add_action('init', array(get_called_class(), '_do_register_taxonomy'));

        $taxonomy_class = static::get_taxonomy_class();
        run_every(600, function () use ($taxonomy_class) {
            foreach ($taxonomy_class::get_all() as $stream) {
                $stream_name   = $stream->as_wp_term()->name;
                $stream_slug   = $stream->as_wp_term()->slug;
                $taxonomy_slug = $taxonomy_class::get_taxonomy_slug();
                (new BatchTask())
                    ->set_banner("Syncing $taxonomy_slug $stream_name ($stream_slug)")
                    ->set_prometheus_labels(array(
                            'kind' => $taxonomy_slug,
                            'slug' => $stream_slug
                        ))
                    ->run(array($stream, "sync"));
            }
        });
    }

    /**
     * Make the taxonomy of @link get_taxonomy_class exist.
     */
    static function _do_register_taxonomy ()
    {
        $taxonomy_class = static::get_taxonomy_class();
        $taxonomy_slug = $taxonomy_class::get_taxonomy_slug();
        $post_class = $taxonomy_class::get_post_class();
        $post_slug = $post_class::get_post_type();
        register_taxonomy(
            $taxonomy_slug,
            array($post_slug),
            array(
                'hierarchical'      => false,
                'labels'            => static::get_human_labels(),
                'show_ui'           => true,
                'show_admin_column' => true,
                'query_var'         => true,
                'capabilities'      => array(
                    // Cannot reassign channels from post edit screen:
                    'assign_terms' => '__NEVER_PERMITTED__',
                    // Default permissions apply for the other operations
                ),
                'rewrite'           => array( 'slug' => $taxonomy_slug ),
            ));
        add_action("${taxonomy_slug}_add_form_fields", array(get_called_class(), "create_channel_widget"));
        add_action( "${taxonomy_slug}_edit_form_fields", array(get_called_class(), "update_channel_widget"), 10, 2);
        add_action( "created_${taxonomy_slug}", array(get_called_class(), 'edited_channel'), 10, 2 );
        add_action( "edited_${taxonomy_slug}", array(get_called_class(), 'edited_channel'), 10, 2 );
    }

    static function create_channel_widget ($taxonomy)
    {
        self::render_channel_widget(array("placeholder" => static::get_placeholder_api_url(), "size" => 40, "type" => "text"));
    }

    static function _get_wp_admin_label ($key)
    {
        $labels = static::get_human_labels();
        if (array_key_exists($key, $labels)) {
            return $labels[$key];
        }
        $default_labels = array(
            "url_legend" => ___("Channel API URL"),
            "url_legend_long" => ___("Source URL of the JSON data."),
        );
        return $default_labels[$key];
    }

    static function update_channel_widget ($term, $unused_taxonomy_slug)
    {
        $taxonomy_class = static::get_taxonomy_class();
        $current_url = (new $taxonomy_class($term))->get_url();
        ?><tr class="form-field epfl-ws-channel-url-wrap">
            <th scope="row">
                <label for="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>">
                    <?php echo self::_get_wp_admin_label("url_legend"); ?>
                </label>
            </th>
            <td>
                <input id="<?php echo self::CHANNEL_WIDGET_URL_SLUG; ?>" name="<?php echo self::CHANNEL_WIDGET_URL_SLUG; ?>" type="text" size="40" value="<?php echo $current_url; ?>" />
                <p class="description"><?php echo self::_get_wp_admin_label("url_legend_long"); ?></p>
            </td>
        </tr><?php
    }

    const CHANNEL_WIDGET_URL_SLUG = 'epfl_channel_url';

    static function render_channel_widget ($input_attributes)
    {
      ?><div class="form-field term-wrap">
        <label for="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>"><?php echo self::_get_wp_admin_label("url_legend"); ?></label>
        <input id="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>" name="<?php echo self::CHANNEL_WIDGET_URL_SLUG ?>" <?php
           foreach ($input_attributes as $k => $v) {
               echo "$k=" . htmlspecialchars($v) . " ";
           }?> />
       </div><?php
    }

    static function edited_channel ($term_id, $tt_id)
    {
        $taxonomy_class = static::get_taxonomy_class();
        $stream = new $taxonomy_class($term_id);
        $stream->set_url($_POST[self::CHANNEL_WIDGET_URL_SLUG]);
        $stream->sync();
    }
}

/**
 * Abstract base class for posts
 *
 * Since WP_Post is final (https://core.trac.wordpress.org/ticket/24672),
 * the next best thing is a has-a relationship.
 */
abstract class Post
{
   /**
    * Subclasses should define this method to return a Boolean
    * indicating whether $this->ID points to a "real" instance
    * of said subclass.
    */
    abstract protected function _belongs ();

    /**
     * Protected constructor
     */
    protected function __construct ($id)
    {
        $this->ID = $id;
    }

    /**
     * Wrap one WP_Post in a Post instance
     *
     * @return an instance of this class or null
     */
    static function get ($post_or_post_id)
    {
        if (is_object($post_or_post_id)) {
            $post_id = $post_or_post_id->ID;
        } else {
            $post_id = $post_or_post_id;
        }

        $theclass = get_called_class();
        $that = new $theclass($post_id);
        if (is_object($post_or_post_id)) {
            $that->_wp_post = $post_or_post_id;
        }
        if (! $that->_belongs()) { return; }
        return $that;
    }

    function wp_post ()
    {
        if (! $this->_wp_post) {
            $this->_wp_post = get_post($this->ID);
        }
        return $this->_wp_post;
    }
}

/**
 * Abstract base class for posts that belong to a custom post type
 */
abstract class TypedPost extends Post
{
    /**
     * @return the post_type slug for instances of this class
     */
    static abstract function get_post_type ();

    /**
     * Protected constructor
     */
    protected function __construct ($id)
    {
        $this->ID = $id;
    }

    function _belongs ()
    {
        return ($this->wp_post()->post_type ===
                get_called_class()::get_post_type());
    }

    /**
     * Retrieve one post per its primary key components.
     *
     * @param $unique_key_array A PHP associative array whose keys
     * are the names of WordPress "meta" fields that compose the Post's
     * unique identity, and whose values are the corresponding values.
     */
    static protected function _get_by_primary_key ($unique_key_array)
    {
        $theclass = get_called_class();
        $q = new _WPQueryBuilder($theclass::get_post_type());
        $that = new $theclass($q->by_meta($unique_key_array)->result()->ID);
        foreach ($unique_key_array as $k => $v) {
            $that->$k = $v;
        }
        return $that;
    }

    /**
     * Like @link _get_by_primary_key, except create a new WordPress
     * post if searching by $unique_key_array yields no results.
     */
    static protected function _get_or_create ($unique_key_array)
    {
        $that = static::_get_by_primary_key($unique_key_array);
        if ($that !== null) { return $that; }

        $theclass = get_called_class();
        $id = wp_insert_post(array(
            "post_type" => $theclass::get_post_type(),
            "post_status" => "publish",
            "meta_input" => $unique_key_array), true);
        $that = new $theclass($id);
        foreach ($unique_key_array as $k => $v) {
            $that->$k = $v;
        }
        return $that;
    }

    /**
     * Iterate with $callback over all instances of this class.
     *
     * @param $callback A callable that will be called with each
     * instance of this class in turn. The WordPress global variables
     * will be updated as if within "The loop".
     */
    public static function foreach ($callback)
    {
        $all = (new _WPQueryBuilder(static::get_post_type()))->wp_query();
        $in_the_loop = new _InTheLoopHelper($all);
        try {
            $in_the_loop->enter();

            while ($all->have_posts()) {
                $all->next_post();
                if ($epfl_post = static::get($all->post)) {
                    call_user_func($callback, $epfl_post);
                }
            }
        } finally {
            $in_the_loop->leave();
        }
    }
}

/**
 * A helper to help us pretend that we are @link in_the_loop .
 *
 * While there is telling whether being "in the loop" will help us in
 * any way whatsoever with procuring sex and/or drugs, it does help
 * with WordPress avoiding a so-called "N+1 query problem" with
 * thumbnails.
 *
 * To wit, post-thumbnail-template.php has code that goes like
 *
 * 		if ( in_the_loop() )
 *			update_post_thumbnail_cache();
 *
 * This means a contrario that when not in_the_loop(), each and every
 * call to get_the_post_thumbnail() and friends, results in a new
 * query to the database.
 *
 * @see https://secure.phabricator.com/book/phabcontrib/article/n_plus_one/
 * @see https://www.reddit.com/r/OutOfTheLoop/
 */
class _InTheLoopHelper
{
    /**
     * Prepare to pretend that $wp_query is the main query (which is what
     * in_the_loop() checks).
     */
    function __construct ($wp_query)
    {
        $this->wp_query = $wp_query;
    }

    /**
     * Start pretending to be in the loop.
     */
    function enter ()
    {
        $this->_in_the_loop_orig = $this->wp_query->in_the_loop;
        $this->wp_query->in_the_loop = true;

        global $wp_query;
        $this->_wp_query_orig = $wp_query;
        $wp_query = $this->wp_query;
    }

    /**
     * Stop pretending to be in the loop and restore all state (both
     * global and in the $wp_query object passed to the constructor)
     * as it was before @link enter.
     *
     * Should be called from a "finally" block.
     */
    function leave ()
    {
        $this->wp_query->in_the_loop = $this->_in_the_loop_orig;

        global $wp_query;
        $wp_query = $this->_wp_query_orig;
    }
}

/**
 * Abstract base class for Actu and Memento posts
 *
 * There is one instance of this class for every unique piece of news
 * (identified by the "news_id"/"event_id" and "translation_id" API
 * fields, and materialized as a WordPress "post" object of post_type
 * == 'epfl-actu' or 'epfl-memento').
 */

abstract class APIChannelPost extends TypedPost
{
    /**
     * @return The name of the primary key component for the API ID,
     *         i.e. either "news_id" or "event_id"
     */
    static abstract function get_api_id_key ();

    /**
     * @return The name of key in API results whose value is the
     *         picture URL.
     */
    static abstract function get_image_url_key ();

    /**
     * @return The @link APIAutoCategory class that instances of
     *         this class get auto-attached to.
     */
    static abstract function get_auto_category_class ();

    /**
     * @return The key in the JSON returned in the API, whose value
     *         is an integer ID pointing to the correct instance
     *         of @link get_auto_category_class
     */
    static abstract function get_auto_category_id_key ();

    /**
     * Extract as much information as desired from $api_result and
     * stash it into $this->_post_meta.
     *
     * The base class only picks "absolute_slug" out of $api_result.
     * Subclasses are expected to pick up additional fields.
     *
     * See also @link _update_image_meta which is ran after
     * _update_post_meta.
     */
    protected function _update_post_meta ($api_result)
    {
        $this->_post_meta["absolute_slug"] = $api_result["absolute_slug"];
        // So technically we've been through a decode/encode cycle and
        // we all know how harsh PHP can be to JSON... But we just want
        // to keep as much metadata as possible.
        $this->_post_meta["json_bkp"] = json_encode($api_result);
    }

    /**
     * @return the value of the "post_content" standard (non-meta) WordPress field.
     */
    abstract protected function _get_content ($api_result);

    /**
     * @return the value of the "post_title" standard (non-meta) WordPress field.
     *
     * Both APIs happen to use the same field, however this method is
     * singled out for overloading in case of need.
     */
    protected function _get_title ($api_result)
    {
        return $api_result["title"];
    }

    /**
     * @return the value of the "post_excerpt" standard (non-meta) WordPress
     * field.
     *
     * The base class does nothing. If your API has excerpts (e.g.,
     * Actu), override in the subclass.
     */
    protected function _get_excerpt ($api_result) {
        return null;
    }

    var $ID;
    private $api_id;
    private $translation_id;

    function get_api_id () { return $this->api_id; }
    function get_translation_id () { return $this->translation_id; }

    const LANGUAGE_SLUG = "language";
    function get_language () {
        return get_post_meta($this->ID, self::LANGUAGE_SLUG, true);
    }

    /**
     * Populated piecemeal at @link sync time by the various
     * _update_* and _set_* methods; fetched all at once by
     * @link _get_post_meta
     */
    protected $_post_meta;

    /**
     * Private constructor — Call @link get or @link sync instead
     */
    protected function __construct ($id, $api_id = null, $translation_id = null)
    {
        parent::__construct($id);
        if ($api_id !== null) {
            $this->api_id = $api_id;
        } else {
            $this->api_id = get_post_meta($id, "api_id", true);
        }
        if ($translation_id !== null) {
            $this->translation_id = $translation_id;
        } else {
            $this->translation_id = get_post_meta($id, "translation_id", true);
        }
    }

    /**
     * Retrieve one post, only if it does exist.
     *
     * @return an instance of this class or null.
     */
    static function get ($post_or_post_id)
    {
        $theclass = get_called_class();
        $expected_post_type = $theclass::get_post_type();
        if (is_object($post_or_post_id)) {
            if ($post_or_post_id->post_type !== $expected_post_type) return;
            $post_id = $post_or_post_id->ID;
        } else {
            $post_id = $post_or_post_id;
            if (get_post_type($post_id) !== $expected_post_type) return;
        }

        $api_id         = get_post_meta($post_id, "api_id",         true);
        $translation_id = get_post_meta($post_id, "translation_id", true);
        $that = new $theclass($post_id, $api_id, $translation_id);
        if (is_object($post_or_post_id)) {
            $that->_wp_post = $post_or_post_id;
        }
        return $that;
    }

    const THUMBNAIL_META  = "epfl_external_thumbnail";

    /**
     * Create or update an instance from $api_result
     *
     * @param $api_result One of the API results returned by
     *        @link ActuAPIClient#fetch
     *
     * @return The object instance that corresponds to the primary keys
     *         present in $api_result
     */
    static function sync ($api_result)
    {
        if (! (array_key_exists(static::get_api_id_key(), $api_result) &&
               array_key_exists("translation_id", $api_result))) {
            throw new Exception("Invalid API result: " . var_export($api_result, true));
        }
        $that = static::_get_or_create(array(
            "api_id"         => $api_result[static::get_api_id_key()],
            "translation_id" => $api_result["translation_id"]));
        $that->_sync($api_result);
        return $that;
    }

    public function _sync($api_result)
    {
        $this->_init_post_meta();

        // "absolute_slug" is common to both APIs
        $this->_post_meta["absolute_slug"] = $api_result["absolute_slug"];
        $lang = $this->_get_api_language($api_result);
        $this->_post_meta[self::LANGUAGE_SLUG] = $lang;

        $this->_update_post_meta($api_result);
        $this->_update_image_meta($api_result);

        $update_post_args = array(
            "ID"            => $this->ID,
            "post_type"     => $this->get_post_type(),
            "post_title"    => $this->_get_title($api_result),
            "post_content"  => $this->_get_content($api_result),
            "meta_input"    => $this->_post_meta
        );

        $excerpt = $this->_get_excerpt($api_result);
        if ($excerpt) {
            $update_post_args["post_excerpt"] = $excerpt;
        }

        wp_update_post($update_post_args);
        AutoFields::of(get_called_class())->append(array_keys($this->_post_meta));

        // Polylang
        if (function_exists("pll_set_post_language")) {
            pll_set_post_language($this->ID, $lang);
        }
        if (function_exists("pll_save_post_translations") &&
            count($translations = $this->_get_translations()))
        {
            error_log("pll_save_post_translations(" . var_export($translations, true) . ")");  // XXX
            pll_save_post_translations($translations);
        }

        $auto_categories = $this->get_auto_category_class()::find(
            $api_result[$this->get_auto_category_id_key()],
            /* $lang_hint = */ $this->_get_api_language($api_result));
        $auto_category_ids = array_map(function($cat) { return $cat->ID(); }, $auto_categories);
        _debug(sprintf("Appending %s to post %d", var_export($auto_category_ids, true), $this->ID));
        wp_set_post_categories(
            $this->ID,
            array_map(function($cat) { return $cat->ID(); }, $auto_categories),
            /* $append = */ true);
    }

    function _get_api_language ($api_result) {
        return ($api_result["language"] ? $api_result["language"] :
                $api_result["lang"]);
    }

    private function _init_post_meta ()
    {
        $this->_post_meta = array(
            "api_id"         => $this->api_id,
            "translation_id" => $this->translation_id
        );
    }

    protected function _get_post_meta ()
    {
        if (! $this->_post_meta) {
            $this->_init_post_meta();
            foreach (get_post_meta($this->ID) as $key => $array) {
                // All meta keys are single-valued
                $this->_post_meta[$key] = $array[0];
            }
        }
        return $this->_post_meta;
    }

    protected function _get_translations ()
    {
        $translations = array();
        $q = new _WPQueryBuilder($this->get_post_type());
        foreach ($q->by_meta(array("api_id" => $this->get_api_id()))->results()
                 as $post) {
            $lang = static::get($post)->get_language();
            if ($lang) {
                $translations[$lang] = $post->ID;
            }
        }
        return $translations;
    }

    const MAX_HEIGHT_META = "epfl_external_img_max_height";
    const MAX_WIDTH_META  = "epfl_external_img_max_width";

    function get_max_size ()
    {
        $meta = $this->_get_post_meta();
        if ($meta[self::MAX_HEIGHT_META] &&
            $meta[self::MAX_WIDTH_META]) {
            return array("height" => $meta[self::MAX_HEIGHT_META],
                         "width"  => $meta[self::MAX_WIDTH_META]);
        } else {
            return null;
        }
    }

    /**
     * Extract the image-related metadata from $api_result and
     * stash them into $this->_post_meta.
     *
     * The base class obtains and stashes the thumbnail from the key
     * named by @link get_image_url_key, and then probes the maximum
     * image size server-side (see @link get_image_url for details on
     * this feature of EPFL Actu and Memento). Subclasses may decide
     * to override the method, and even forego calling the parent::
     * altogether, if they know better what image to use (e.g. YouTube
     * thumbnail for Actus with videos).
     *
     */
    protected function _update_image_meta ($api_result)
    {
        $key = $this->get_image_url_key();
        $image_url = $api_result[$key];
        if (! $image_url) {
            _debug("No key $key for post ID " . $this->ID);
            return;
        }
        $this->_set_thumbnail_url($image_url);
        $extra_big_image_url = $this->get_image_url("8000x6000");
        if (! $extra_big_image_url) {
            _debug(sprintf("Post %d: unable to scale %s", $this->ID, $image_url));
            return;
        }
        $max_size = get_image_size($extra_big_image_url);
        $this->_post_meta[self::MAX_HEIGHT_META] = $max_size["height"];
        $this->_post_meta[self::MAX_WIDTH_META]  = $max_size["width"];
        return $image_url;
    }

    /**
     * @return the URL for a server-side resized image of $size
     *
     * @param $size e.g. "1024x768". If omitted, utilize the thumbnail
     *        size as returned by the API.
     */
    function get_image_url ($size = null)
    {
        $url = $this->_get_post_meta()[self::THUMBNAIL_META];
        if (! $url) {
            _debug("No " . self::THUMBNAIL_META . " found for post ID " . $this->ID);
            return null;
        }
        if (! $size) return $url;
        $matched = array();
        if (preg_match("/^(.*)\/(\d+x\d+)\.([a-zA-z]{1,6})$/", $url, $matched)) {
            return sprintf("%s/%s.%s", $matched[1], $size, $matched[3]);
        } else {
            return $url;
        }
    }

    /**
     * The opposite of @link get_image_url when called without arguments
     *
     * To be called from an _update_* method only.
     */
    protected function _set_thumbnail_url ($url)
    {
        $this->_post_meta[self::THUMBNAIL_META] = $url;
    }

    function get_permalink ()
    {
        $meta = $this->_get_post_meta();
        return $meta["absolute_slug"];
    }
}

/**
 * Abstract base class for all controller classes
 */
abstract class Controller
{
    /**
     * @return The model class for this controller
     */
    abstract static function get_model_class ();

    /**
     * Arrange for a nonfatal error to be shown in a so-called "admin notice."
     */
    static protected function admin_error ($post_id, $text)
    {
        // Use "Saving It in a Transient" technique from
        // https://www.sitepoint.com/displaying-errors-from-the-save_post-hook-in-wordpress/
        set_transient(
            self::get_error_transient_key($post_id),
            $text,
            45);  // Seconds before it self-destructs
    }

    static private function get_error_transient_key ($post_id)
    {
        return sprintf("epfl-admin-notices_%d_%d",
                       $post_id, get_current_user_id());
    }

    static function _render_admin_errors_if_any ()
    {
        global $post_id;
        $key = Controller::get_error_transient_key ($post_id);
        if ($error = get_transient($key)) {
            delete_transient($key);
            ?>
    <div class="notice notice-error is-dismissible">
        <p><?php echo $error; ?></p>
    </div><?php
        }
    }
}

add_action('admin_notices',
           array(Controller::class, '_render_admin_errors_if_any'));
    
/**
 * Abstract base class for the controller of a model class that
 * consists of a custom post type that itself derives from abstract
 * class @link TypedPost.
 *
 * This is a "pure static" class; no instances are ever constructed.
 *
 */
abstract class CustomPostTypeController extends Controller
{
    static $_columns;
    /**
     * Add or mutate a column in the WP_List_Table list view in wp-admin
     *
     * @return an instance of @link _CustomPostTypeControllerColumn
     */
    static function column ($slug) {
        if (! static::$_columns[$slug]) {
            $_columns[$slug] = new _CustomPostTypeControllerColumn
                             (get_called_class(), $slug);
        }
        return $_columns[$slug];
    }

    /**
     * Add a column in the list view that shows thumbnails
     */
    static function add_thumbnail_column ()
    {
        static::column("thumbnail")
            ->set_title(___( 'Thumbnail' ))
            // Use of static::render_thumbnail_column() is the default
            ->add_css("
td.column-thumbnail img {
    max-width: 100%;
    height: auto;
}
")
            ->hook_after(1);
    }

    /**
     * Echo what should be in the relevant <td> in the thumbnail column.
     *
     * The base class echoes a single <img> tag. Subclasses might want
     * to echo something instead of, or in addition to that.
     *
     * @param $epfl_post An instance of the class returned by @link
     * get_model_class
     */
    static function render_thumbnail_column ($epfl_post)
    {
        $img = get_the_post_thumbnail($epfl_post);
        if (! $img) return;
        echo $img;
    }

    static $_syncing_on_save = null;
    /**
     * Call ->sync() upon saving a TypedPost object.
     *
     * Call this method from the initialization code (e.g. a `hook`
     * method) in order to enable this functionality for all instances
     * of the @link get_model_class.
     */
    static function call_sync_on_save ()
    {
        $model_class = static::get_model_class();
        add_action(
            sprintf('save_post_%s', $model_class::get_post_type()),
            function ($post_id, $post, $is_update) use ($model_class) {
                if (CustomPostTypeController::$_syncing_on_save === $post_id) {
                    return;  // Break out of possible recursion
                }
                $syncing_on_save_orig = CustomPostTypeController::$_syncing_on_save;
                try {
                    CustomPostTypeController::$_syncing_on_save = $post_id;
                    $model_obj = $model_class::get($post);
                    if ($model_obj) { $model_obj->sync(); }
                } finally {
                    CustomPostTypeController::$_syncing_on_save = $syncing_on_save_orig;
                };
            }, 10, 3);
    }

    static function add_editor_css ($css)
    {
        add_action('admin_head', function () use ($css) {
            echo "<style>$css</style>";
        });
    }
}

/**
 * A custom column in the WP_List_Table view
 */
class _CustomPostTypeControllerColumn
{
    /**
     * "private" constructor, call ::column() in your
     * @link CustomPostTypeController subclass instead.
     */
    function __construct ($owner_controller, $slug)
    {
        $this->owner = $owner_controller;
        $this->slug  = $slug;

        // Default settings:
        $this->set_title($slug);
        $this->set_renderer(array($owner_controller, "render_${slug}_column"));
    }

    function set_title ($translated_title)
    {
        $this->title = $translated_title;
        return $this;  // Chainable
    }

    function add_css ($css)
    {
        $this->css .= $css;
        return $this;
    }

    function set_renderer ($callable)
    {
        $this->render = $callable;
        return $this;
    }

    function make_sortable ($sort_opts)
    {
        $this->sort_opts = $sort_opts;
        return $this;
    }

    function hook_after ($after)
    {
        $post_type = $this->get_post_type();
        $this->insert_after = $after;
        add_action(
            sprintf('manage_%s_posts_columns', $post_type),
            array($this, '_filter_posts_columns'));

        add_action(
            sprintf('manage_%s_posts_custom_column', $post_type),
            array($this, '_filter_manage_custom_column'),
            10, 2);

        add_action('admin_head', array($this, '_render_css'));

        // Sorting
        add_action('pre_get_posts', array($this, '_action_pre_get_posts'));
        add_filter(
            sprintf('manage_edit-%s_sortable_columns', $post_type),
            array($this, '_filter_manage_sortable_columns'));

        return $this;   // Chainable, because why not?
    }

    protected function get_model_class ()
    {
        $controller_class = $this->owner;
        return $controller_class::get_model_class();
    }

    protected function get_post_type ()
    {
        $model_class = $this->get_model_class();
        return $model_class::get_post_type();
    }

    function _filter_posts_columns ($columns)
    {
        if (is_int($this->insert_after)) {
            // https://stackoverflow.com/a/3354804/435004
            $newcolumns = array_merge(
                array_slice($columns, 0, $this->insert_after, true),
                array($this->slug => $this->title),
                array_slice($columns, $this->insert_after,
                            count($columns) - $this->insert_after, true));
            return $newcolumns;
        } elseif (is_string($this->insert_after)) {
            $newcolumns = array();
            foreach ($columns as $col_slug => $descr) {
                $newcolumns[$col_slug] = $descr;
                if ($col_slug === $this->insert_after) {
                    $newcolumns[$this->slug] = $this->title;
                }
            }
            return $newcolumns;
        } else {
            wp_die("Unsupported value for ->insert_after: " . var_export($this->insert_after, true));
        }
    }

    function _filter_manage_custom_column ($column, $post_id)
    {
        if ($column !== $this->slug) return;

        $model_class = $this->get_model_class();
        $epfl_post = $model_class::get($post_id);
        if (! $epfl_post) return;

        call_user_func($this->render, $epfl_post);
    }

    function _filter_manage_sortable_columns ($columns)
    {
        if ($this->sort_opts) {
            $columns[$this->slug] = $this->slug;
        }
        return $columns;
    }

    /**
     * Honor requests to sort by this column
     */
    function _action_pre_get_posts ($query)
    {
        if ( ! is_admin() ) return;
        if (! $this->sort_opts) return;
        if ($query->get( 'orderby' ) !== $this->slug) return;

        // Here we could examine $this->sort_opts to support sorting
        // by something other than a meta_key.
        $query->set('orderby', 'meta_value');
        $query->set('meta_key', $this->sort_opts['meta_key']);
    }

    function _render_css ()
    {
        if (! $this->css) return;
        echo '<style>' . $this->css . '</style>';
        $this->css = null;
    }
}

/**
 * Abstract base class for the controller of a model class whose
 * instances are streamed from an API (as opposed to typed in manually
 * from wp-admin).
 *
 * This is a "pure static" class; no instances are ever constructed.
 *
 * Posts managed by a subclass of StreamPostController are displayed
 * read-only in the wp-admin area, and are assumed to have a
 * "homepage" that is external to WordPress (obtained with the
 * `get_permalink` method in the class returned by @link
 * get_model_class).
 */
abstract class StreamPostController extends CustomPostTypeController
{
    /**
     * Get the labels to display in various places in the UI.
     *
     * @return An associative array whose keys are i18n-neutral
     *         keywords and whose values are translation strings. This
     *         array gets passed as-is as the 'labels' value to
     *         WordPress' @link register_post_type, and therefore ought
     *         to contain like-named keys. Additionally the following
     *         keys are used by StreamTaxonomyController directly:
     *
     * - description: The post type description for wp_admin (passed
     *                as the `"description"` field to `register_post_type`)
     *
     */
    abstract static function get_human_labels ();

    /**
     * @return The @link StreamTaxonomy subclass this controller serves.
     */
    abstract static function get_taxonomy_class ();

    /**
     * Filter the arguments before they are passed to Wordpress' @link
     * register_post_type
     *
     * The base class does nothing. Subclasses may want to set e.g.
     * 'menu_icon' or 'menu_position'.
     */
    static function filter_register_post_type (&$args) {
    }

    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        plugin_activation_hook(array(get_called_class(), "register_caps"));
        plugin_deactivation_hook(array(get_called_class(), "deregister_caps"));

        // Behavior of epfl-ws posts on the main site
        add_filter("post_type_link",
                   array(get_called_class(), "filter_post_link"), 10, 4);

        // Behavior of epfl-ws posts in the wp-admin area
        static::configure_rendering_in_edit_form();
        add_action("admin_enqueue_scripts", array(get_called_class(), "editor_css"));
        static::auto_fields_controller()->hook();
        add_action('admin_init', array(get_called_class(), 'make_subtitles_readonly_in_admin'), 0);

        // Behavior of epfl-ws posts in search results
        add_filter('pre_get_posts', array(get_called_class(), "pre_get_posts"));
    }

    private static function auto_fields_controller () {
        return new AutoFieldsController(self::get_model_class());
    }

    /**
     * Make it so that posts of this class exist.
     *
     * Under WordPress, almost everything publishable is a post.
     * register_post_type() is invoked to create a particular flavor
     * of posts that describe news.
     */
    static function register_post_type ()
    {
        $taxonomy_class = static::get_taxonomy_class();
        $taxonomy_slug = $taxonomy_class::get_taxonomy_slug();
        $post_class = $taxonomy_class::get_post_class();
        $labels = static::get_human_labels();

        $post_type_settings = array(
                'labels'             => $labels,
                'description'        => $labels['description'],
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'query_var'          => true,
                // ad hoc access control, see (de|)register_caps() below:
                'capabilities'       => array(
                    'read'                 => 'read_epfl_ws_posts',
                    // Name notwithstanding, edit_posts is actually
                    // the permission to see the list of posts.
                    'edit_posts'           => 'read_epfl_ws_posts',
                    'create_posts'         => '__NEVER_PERMITTED__',

                    'edit_post'            => 'edit_epfl_ws_posts',
                    'edit_private_posts'   => 'edit_epfl_ws_posts',
                    'edit_published_posts' => 'edit_epfl_ws_posts',
                    'assign_categories'    => 'edit_epfl_ws_posts',
                    'assign_post_tags'     => 'edit_epfl_ws_posts',
                    // One is not normally supposed to delete streamed
                    // posts manually — This is just an escape hatch
                    // in case a StreamTaxonomy was deleted and
                    // referential integrity was breached.
                    'delete_post'          => 'edit_epfl_ws_posts',
                    'delete_posts'         => 'edit_epfl_ws_posts',
                    'delete_private_posts' => 'edit_epfl_ws_posts',
                    'delete_others_posts'  => 'edit_epfl_ws_posts',
                ),
                'has_archive'        => true,
                'hierarchical'       => false,
                'taxonomies'         => array($taxonomy_slug, 'category', 'post_tag'),
                'menu_position'      => 40,
                'menu_icon'          => isset($menu_icon) ? $menu_icon : '',
                'supports'           => array('wps_subtitle', 'custom-fields'),
                'register_meta_box_cb' => array(get_called_class(), 'add_meta_boxes')
            );
        static::filter_register_post_type($post_type_settings);
        register_post_type($post_class::get_post_type(), $post_type_settings);
    }

    const ROLES_THAT_MAY_VIEW = array('administrator', 'editor', 'author', 'contributor');
    const ROLES_THAT_MAY_MANAGE = array('administrator', 'editor');
    const ALL_ROLES = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
    const CAPS_FOR_VIEWERS = array(
        'read_epfl_ws_posts'
    );
    const ALL_CAPS = array(
        'read_epfl_ws_posts',
        'edit_epfl_ws_posts'
    );
    // We have used these in the past, and need to clean them up.
    const OBSOLETE_CAPS = array(
        'read_epfl_actus',
        'edit_epfl_actus',
        'read_epfl_actu',
        'delete_epfl_actu'
    );

    /**
     * Register permissions ("capabilities") on epfl-ws posts.
     *
     * Called at plugin activation time.
     *
     * The permission map is made so that administrators and editors can view and
     * delete epfl-ws posts, but not edit them.
     */
    static function register_caps ()
    {
        foreach (self::ROLES_THAT_MAY_VIEW as $role_name) {
            $role = get_role($role_name);
            foreach (self::CAPS_FOR_VIEWERS as $cap) {
                $role->add_cap($cap);
            }
        }
        foreach (self::ROLES_THAT_MAY_MANAGE as $role_name) {
            $role = get_role($role_name);
            foreach (self::ALL_CAPS as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    /**
     * De-register permissions ("capabilities") on epfl-ws posts.
     *
     * Called at plugin deactivation time.
     */
    static function deregister_caps ()
    {
        foreach (self::ALL_ROLES as $role_name) {
            $role = get_role($role_name);
            foreach (self::ALL_CAPS as $cap) {
                $role->remove_cap($cap);
            }
            foreach (self::OBSOLETE_CAPS as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    /**
     * Serve the permalink from actu.epfl.ch / memento.epfl.ch instead of our own.
     *
     * Mostly, we keep the full text of the article in-database just for the search engine.
     */
    static function filter_post_link ($orig_link, $post, $unused_leavename, $unused_is_sample)
    {
        $epfl_post = static::get_model_class()::get($post);
        if (! $epfl_post) return $orig_link;
        $true_permalink = $epfl_post->get_permalink();
        return $true_permalink ? $true_permalink : $orig_link;
    }

    static function configure_rendering_in_edit_form ()
    {
        $this_class = get_called_class();
        add_action("edit_form_after_title", function ($wp_post) use ($this_class) {
                $epfl_post = $this_class::get_model_class()::get($wp_post);
                if (! $epfl_post) return;
               $this_class::render_readonly_in_edit_form($epfl_post);
            });

    }

    function render_readonly_in_edit_form ($epfl_post)
    {
        $wp_post = $epfl_post->wp_post();
        $permalink = get_permalink($wp_post);
        global $post;
        $subtitle = function_exists("get_the_subtitle") ? get_the_subtitle($post, "", "", false) : null;
        ?>
    <h1><?php echo $wp_post->post_title; ?></h1>
    <?php if ($subtitle) : ?><h2><?php echo $subtitle; ?></h2><?php endif; ?>
	<div id="edit-slug-box" class="hide-if-no-js">
    <?php if (method_exists($epfl_post, "get_image_url")): ?>
    <img class="epfl-ws-thumbnail" src="<?php echo $epfl_post->get_image_url() ?>"/>
    <?php endif; ?>
    <p><b>Permalink:</b> <a href="<?php echo $permalink; ?>"><?php echo $permalink; ?></a></p>
    <?php echo $wp_post->post_content; ?>
	</div>
        <?php
    }

    static function editor_css ($hook)
    {
        if (! ('post.php' === $hook &&
               static::get_model_class()::get($_GET["post"])) ) return;
        wp_register_style(
            'ws-editor',
            plugins_url( 'ws-editor.css', __FILE__ ) );
        wp_enqueue_style('ws-editor');
    }

    /**
     * Make subtitles read-only by preventing WP Subtitles from
     * initializing in the case of epfl-ws posts.
     */
    static function make_subtitles_readonly_in_admin ()
    {
		$post_type = '';

		if ( isset( $_REQUEST['post_type'] ) ) {
			$post_type = sanitize_text_field( $_REQUEST['post_type'] );
		} elseif ( isset( $_GET['post'] ) ) {
			$post_type = get_post_type( absint( $_GET['post'] ) );
        }
        if ($post_type !== static::get_model_class()::get_post_type()) return;

        remove_action('admin_init', array( 'WPSubtitle_Admin', '_admin_init' ) );
        // Add back the subtitle column for the list view:
        add_filter( 'manage_edit-' . $post_type . '_columns', array( 'WPSubtitle_Admin', 'manage_subtitle_columns' ) );
        add_action( 'manage_' . $post_type . '_posts_custom_column', array( 'WPSubtitle_Admin', 'manage_subtitle_columns_content' ), 10, 2 );
    }

    function pre_get_posts ($query) {
		$qv = &$query->query_vars;

        if (! is_admin() && $query->is_main_query()) {
            // Loosely based on https://wordpress.stackexchange.com/q/181803/132235
            $post_types = $query->get('post_type');
            if ($post_types === 'post') {
                $post_types = ['post'];
            }
            if (is_array($post_types) &&
                (false === array_search(static::get_model_class()::get_post_type(), $post_types))) {
                array_push($post_types, static::get_model_class()::get_post_type());
                $query->set('post_type', $post_types);
            }
        }
        return $query;
    }

    /**
     * @return The model class for this controller
     */
    static function get_model_class ()
    {
        $taxonomy_class = static::get_taxonomy_class();
        return $taxonomy_class::get_post_class();
    }

    /**
     * Called to add meta boxes on an "edit" page in wp-admin.
     *
     * The base class only puts @link AutoFieldsController in gear.
     * Subclasses may want to override this to add more meta boxes.
     */
    static function add_meta_boxes ()
    {
        static::auto_fields_controller()->add_meta_boxes();
    }
}

/**
 * Configuration UI and WP callbacks for an APIChannelPost class.
 *
 * This is a "pure static" class; no instances are ever constructed.
 *
 * Compared to the parent class @link StreamPostController, this class
 * adds support for server-side image resizing; also, it arranges to
 * show thumbnails and metadata (i.e. max image size) in the wp-admin
 * area.
 */
abstract class APIChannelPostController extends StreamPostController
{
    /**
     * Overridden to deal with thumbnails.
     */
    static function hook ()
    {
        parent::hook();

        add_filter("post_thumbnail_html",
                   array(get_called_class(), "filter_post_thumbnail_html"), 10, 5);
        static::add_thumbnail_column();
    }

    /**
     * Arrange for get_the_post_thumbnail() to return the external thumbnail for epfl-ws posts.
     *
     * This is set as a filter for WordPress' @link
     * post_thumbnail_html hook. Note that it isn't as easy to hijack
     * the return value of @link get_the_post_thumbnail_url in this
     * way (but you can always call the @link get_image_url method on
     * an APIChannelPost instance).
     *
     * @return An <img /> tag with suitable attributes
     *
     * @param $orig_html The HTML that WordPress intended to return as
     *                   the picture (unused, as it will typically be
     *                   empty — epfl-ws objects lack attachments)
     *
     * @param $post_id   The post ID to compute the <img /> for
     *
     * @param $size      The requested size, in WordPress notation (either the
     *                   name of a well-known or declared size, or a [$height,
     *                   $width] array)
     *
     * @param $attr      Associative array of HTML attributes. If "class" is
     *                   not specified, the default "wp-post-image" is used
     *                   to match the WordPress behavior for local (attached)
     *                   images.
     */
    static function filter_post_thumbnail_html ($orig_html, $post_id, $unused_thumbnail_id,
                                                $size, $attr)
    {
        $epfl_post = static::get_model_class()::get($post_id);
        if (! $epfl_post) return $orig_html;

        // Actu and Memento images are resizable server-side
        // TODO: we could actually interpret $size in a much finer way
        if (($size === "full" || $size === "large")) {
            $src = $epfl_post->get_image_url("2048x1152");
        } else {
            $src = $epfl_post->get_image_url();
        }
        if (! $src) return $orig_html;

        if (! $attr) $attr = array();
        if (! $attr["class"]) {
            $attr["class"] = "wp-post-image";
        }
        $attrs = "";
        foreach ( $attr as $name => $value ) {
            $attrs .= sprintf(" %s=\"%s\"", $name, esc_attr($value));
        }
        return sprintf("<img src=\"%s\" %s/>", $src, $attrs);
    }

    /**
     * Overridden to show image metadata in the thumbnail column.
     *
     * This class appends size informations below $img. Subclasses
     * may override this to render the thumbnail differently.
     *
     * @param $epfl_post An instance of the class returned by @link
     * get_model_class
     */
    static function render_thumbnail_column ($epfl_post)
    {
        parent::render_thumbnail_column($epfl_post);
        if ($orig_size = $epfl_post->get_max_size()) {
            printf("<p>Size: %dx%d</p>", $orig_size["width"], $orig_size["height"]);
        }
    }
}

/**
 * A category into which APIChannelPost instances can be auto-assigned
 */
abstract class APIAutoCategory
{
    /**
     * @return The object class for WP posts this APIAutoCategory applies to.
     */
    static abstract function get_post_class ();

    /**
     * @return A slug (unique keyword) used to associate metadata
     *         (here, the API-side category code) to objects of this
     *         class in the WordPress database
     */
    static abstract function get_term_meta_slug ();

    /**
     * @return An associative array where keys are API-side integer IDs,
     *         and values are i18n'd human-readable names for same.
     */
    static abstract function get_api_category_names();

    /**
     * Construct the model object for the category whose number is $term_id
     *
     * Note that this works for any category, whether or not it has
     * been made an "auto" category for this APIAutoCategory subclass
     * in the wp admin UI yet.
     */
    function __construct ($term_id)
    {
        $this->term_id = $term_id;
    }

    function ID ()
    {
        return $this->term_id;
    }

    /**
     * @return The EPFL API-side number that encodes for Actu or Memento
     *         records that will auto-assign to this category, or
     *         null if no such relationship has been configured (yet)
     *         in the admin UI.
     */
    function get_api_id ()
    {
        $id_as_text = get_term_meta($this->term_id, $this->get_term_meta_slug(), true);
        return ("" === $id_as_text) ? null : (int) $id_as_text;
    }

    function get_api_category_name ()
    {
        $api_id = $this->get_api_id();
        if (null === $api_id) return __x("(Not set)", "API category name not set");
        return $this->get_api_category_names()[$api_id];
    }

    /**
     * @return A list of categories to auto-include an instance
     * of @link APIChannelPost into.
     *
     * @param $api_id One of the numeric IDs listed by
     *        https://actus.epfl.ch/api/v1/categories/ or equivalent,
     *        as received in the JSON response
     *
     * @param $language_hint A two-letter language code. In case there
     *        are several matching categories *and* the Polylang plug-in is
     *        active, only retain the one(s) that have the same language.
     */
    static function find ($api_id, $language_hint = null)
    {
        $theclass = get_called_class();
        $terms = get_terms(array(
            'taxonomy'   => 'category',
            'meta_key'   => static::get_term_meta_slug(),
            'meta_value' => $api_id,
            'hide_empty' => false
        ));
        if (! count($terms)) return [];
        if (count($terms) > 1 && null !== $language_hint &&
            function_exists("pll_get_term")) {  // Polylang
            // Perhaps the multiple $terms are translations of each other?
            $terms = array_values(array_filter(
                $terms,
                function($term) use ($language_hint) {
                    return ($term->term_id ===
                            pll_get_term($term->term_id, $language_hint));
                }));
        }
        // No Polylang? No matter: just keep the first one (a notch
        // better than inserting into all of them, which we *could* do
        // too from here)
        if (count($terms)) {
            return [new $theclass($terms[0]->term_id)];
        } else {
            return [];
        }
    }
}

/**
 * Configuration UI and WP callbacks for an APIAutoCategory class.
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
abstract class APIAutoCategoryController
{
    /**
     * @return The model class for this controller (subclass of APIAutoCategory)
     */
    abstract static function get_model_class ();

    /**
     * Get the labels to display in various places in the UI.
     *
     * @return An associative array whose keys are i18n-neutral
     *         keywords and whose values are translation strings. The
     *         keys are as shown below:
     *
     * - category_name_label: A short explanation of what the values of
     *                        ::get_model_class()::get_api_category_names()
     *                        are useful for, e.g. "Actu's category name"
     *
     * - purpose_explanation: A longer explanation of same, intended
     *                        for a pop-up tip or help message
     *
     * - column_title:        Same as category_name_label, but for the purpose
     *                        of a column title in a tabular display
     */
    abstract static function get_human_labels ();

    static function hook ()
    {
        add_action ( 'category_add_form_fields',
                     array(get_called_class(),
                           'render_select_api_category_if_post_type_is_right'));
        /* Since the "edit category" form is full-screen, it's confusing
         * to display only one of the drop-down lists depending on how we
         * arrived to that page. */
        add_action ( 'category_edit_form_fields', array(get_called_class(), 'render_select_api_category'));
        add_action ( 'created_category', array(get_called_class(), 'save_category_id'), 10, 2);
        add_action ( 'edited_category', array(get_called_class(), 'save_category_id'), 10, 2);

        add_filter ( "manage_edit-category_columns", array(get_called_class(), 'add_column_category_id'));
        add_filter ( "manage_category_custom_column", array(get_called_class(), 'get_custom_column_value'), 10, 3);
    }

    static function get_term_meta_slug()
    {
        return static::get_model_class()::get_term_meta_slug();
    }

    static function get_custom_column_value ($content, $column_name, $term_id)
    {
        if ($column_name !== static::get_term_meta_slug()) return $content;

        $category_class = static::get_model_class();
        return (new $category_class($term_id))->get_api_category_name();
    }

    static function render_select_api_category_if_post_type_is_right ()
    {
        $auto_category_class = static::get_model_class();
        $post_class = $auto_category_class::get_post_class();
        if ($_REQUEST["post_type"] != $post_class::get_post_type()) return;
        static::render_select_api_category();
    }

    static function render_select_api_category ()
    {
        $auto_category_class = static::get_model_class();
        // Will be null on the create form:
        $current_api_id = (new $auto_category_class($_REQUEST['tag_ID']))->get_api_id();
        $slug = static::get_term_meta_slug();
        ?>
        <tr class="form-field <?php echo static::get_wp_admin_css_class() ?>">
            <th scope="row">
                <label for=<?php echo $slug; ?>>
                    <?php echo static::get_human_labels()["category_name_label"]; ?>
                </label>
            </th>
            <td>
                <select name=<?php echo $slug; ?> id=<?php echo $slug; ?> class="postform">
                    <option value="-1">None</option>
                <?php foreach (static::get_model_class()::get_api_category_names() as $catid => $cattitle) { ?>
                    <option class="level-0" value="<?php echo $catid; ?>"<?php selected($current_api_id, $catid)  ?>><?php echo $cattitle; ?></option>
                <?php } ?>
                </select>
                <p><?php echo static::get_human_labels()["purpose_explanation"]; ?></p>
            </td>
        </tr>
        <?php
    }

    static function save_category_id ($term_id, $unused_taxonomy) {
        $slug = static::get_term_meta_slug();
        $newval = $_REQUEST[$slug];
        if ( null !== $newval ) {
            delete_term_meta($term_id, $slug);
            if ("-1" !== $_REQUEST[$slug]) {
                add_term_meta($term_id, $slug, $newval);
            }
        }
    }

    static function add_column_category_id ($columns)
    {
        $columns[static::get_term_meta_slug()] = static::get_human_labels()["column_title"];
        return $columns;
    }
}

/**
 * A shortcode occurrence.
 *
 * A subclass represents one shortcode. Instances are created for each
 * occurrence of the shortcode.
 */
abstract class Shortcode
{
    function hook ()
    {
        add_shortcode(static::get_name(), function ($atts, $content = null) {
            $thisclass = get_called_class();
           $that = new $thisclass($atts, $content);
            return $that->render();
        });
        if (is_admin()) {
            add_action("admin_print_footer_scripts",
                       array(get_called_class(), '_button_script'));
        }
    }

    static abstract function get_name ();

    function __construct ($attrs, $content = null)
    {
        $this->attrs = $this->_normalize_attrs($attrs);
        $this->content = $content;
    }

    abstract function render ();

    function _button_script ()
    {
        if(! wp_script_is("quicktags")) { return; }
        static::render_button_script();
    }

    /**
     * Render the JS script that calls QTags.addButton() so as to
     * show a helper button in TinyMCE for this shortcode.
     *
     * The base class does nothing.
     */
    function render_button_script ()
    {
    }

    function _normalize_attrs ($attrs)
    {
        // normalize attribute keys, lowercase
        $atts = array_change_key_case((array)$atts, CASE_LOWER);
        return shortcode_atts(static::get_attr_defaults(), $attrs,
                              static::get_name());
    }
}

/**
 * The rendering helper for a shortcode that returns a list of results.
 *
 * Themes can customize that rendering either by calling @link
 * add_filter on "epfl_shortcode_${slug}_list_html" (which overrides
 * the entire class' behavior), or by calling @link add_filter one or
 * more of the following:
 *
 * - epfl_shortcode_${slug}_list_html_no_results
 *
 * - epfl_shortcode_${slug}_list_html_start
 *
 * - epfl_shortcode_${slug}_list_html_item
 *
 * - epfl_shortcode_${slug}_list_html_end
 *
 */
abstract class ListTemplatedShortcodeView
{
    abstract function get_slug ();

    abstract function item_as_html ($item);

    function __construct ($shortcode_attrs)
    {
        $this->shortcode_attrs = $shortcode_attrs;
    }

    function get_no_results_msg ()
    {

    }

    function no_results_as_html ()
    {
        $slug = $this->get_slug();
        return apply_filters(
            "epfl_shortcode_${slug}_list_html_no_results",
            $this->_no_results_as_html_default(),
            $this->shortcode_attrs);
    }

    protected function _no_results_as_html_default ()
    {
        $classes = implode(" ", $this->get_outer_div_classes());
        $msg = __x("No results", "ListShortcode");
        return "<div class=\"$classes no-results\">$msg</div>";
    }

    public function as_html ($items_list)
    {
        $slug = $this->get_slug();

        if (has_filter("epfl_shortcode_${slug}_list_html")) {
            return apply_filters("epfl_shortcode_${slug}_list_html",
                                 "",
                                 $items_list, $this->shortcode_attrs);
        }
        $this->_set_results($items_list);
        if (! $this->_has_results()) {
            return $this->no_results_as_html();
        }

        $classes = implode(" ", $this->get_outer_div_classes());
        $html = apply_filters(
            "epfl_shortcode_${slug}_list_html_start",
            "<div class=\"$classes has-results\">",
            $this->shortcode_attrs);
        while($this->_has_results()) {
            $item = $this->_next_result();
            $html .= apply_filters(
                "epfl_shortcode_${slug}_list_html_item",
                $this->item_as_html($item), $this->shortcode_attrs, $item);
        }
        $html .= apply_filters("epfl_shortcode_${slug}_list_html_end", "</div>",
                               $this->shortcode_attrs);
        return $html;
    }

    protected function _set_results ($item_list)
    {
        $this->_items = $item_list;
    }

    protected function _has_results ()
    {
        return count($this->_items) > 0;
    }

    protected function _next_result ()
    {
        return array_pop($this->_items);
    }

    protected function get_outer_div_classes ()
    {
        $slug = $this->get_slug();
        return array("epfl-shortcode-list", "epfl-shortcode-$slug");
    }
}

/**
 * The rendering helper for a shortcode that returns a list of posts.
 */
abstract class PostListShortcodeView extends ListTemplatedShortcodeView
{
    public function item_as_html ($wp_post)
    {
        $post_type = $wp_post->post_type;
        $html .= '<div class="epfl-ws-shortcode $post_type" id="' . $wp_post->ID . '">';

        if ($url = get_the_permalink($wp_post)) {
            $linkpre = sprintf('<a href="%s">', $url);
            $linkpost = "</a>";
        } else {
            $linkpre = $linkpost = "";
        }

        if ($wp_post->post_title) {
            $html .= '<h2>' . $linkpre . $wp_post->post_title . $linkpost . '</h2>';
        }
        if (function_exists("get_the_subtitle") and
            ($subtitle = get_the_subtitle($wp_post, "", "", false))) {
            $html .= '<p class=\"subtitle\">' . $linkpre . $subtitle . $linkpost . '</p>';
        }
        
        if ($img = get_the_post_thumbnail($wp_post)) {
            $html .= $linkpre . $img . $linkpost;
        }

        if ($excerpt = get_the_excerpt($wp_post)) {
            $html .= sprintf('<p class="excerpt">%s</p>', $excerpt);
        }
        $html .= '</div>';
        return $html;
    }
}

/**
 * The view code for a shortcode whose output consists of the elements
 * returned by a WP_Query object.
 *
 * The rendering is compatible with the WordPress loop and
 * Understrap-style partial templates in the loop-templates/
 * subdirectory of the theme. This means that, in addition to the
 * filter-based customization proposed by the grandparent class @link
 * ListTemplatedShortcodeView, the rendering of a shortcode that uses
 * a subclass of WPQueryShortcodeView can be customized using files
 * whose names are like `loop-templates/content-none.php`,
 * `loop-templates/content-${post_format}.php`,
 * `loop-templates/content-${post_type}.php` and
 * `loop-templates/content.php` as a fallback for the latter two.
 *
 * @see https://understrap.com/
 */
abstract class WPQueryShortcodeView extends PostListShortcodeView
{
    /**
     * @param $q a WP_Query object (instead of a list of items in the
     * parent class)
     */
    public function as_html ($q)
    {
        $in_the_loop = new _InTheLoopHelper($q);
        try {
            $in_the_loop->enter();
            return parent::as_html($q);
        } finally {
            $in_the_loop->leave();
        }
    }

    protected function _set_results ($q)
    {
        $this->q = $q;
    }

    protected function _has_results ()
    {
        return $this->q->have_posts();
    }

    protected function _next_result ()
    {
        $this->q->the_post();
        global $post;
        return $post;
    }

    const UNDERSTRAP_LOOP_TEMPLATE_SLUG = "loop-templates/content";

    protected function has_template ($short_name) {
        if (! $short_name) return;
        return locate_template(
            self::UNDERSTRAP_LOOP_TEMPLATE_SLUG . "-" . $short_name . ".php");
    }

    protected function render_template ($short_name) {
        try {
            ob_start();
            get_template_part(
                self::UNDERSTRAP_LOOP_TEMPLATE_SLUG, $short_name);
            return ob_get_contents();  // NOT ob_get_clean(), lest the finally
                                       // block close one ob too many
        } finally {
            ob_end_clean();
        }
    }

    protected function _no_results_as_html_default ()
    {
        if ($this->has_template("none")) {
            return $this->render_template("none");
        } else {
            return parent::_no_results_as_html_default();
        }
    }

    public function item_as_html ($wp_post)
    {
        // ->_next_result() already took care of setting WordPress'
        // global loop variables
        foreach (array(
            get_post_format($wp_post),
            $wp_post->post_type,
        ) as $maybe_template) {
            if ($this->has_template($maybe_template)) {
                return $this->render_template($maybe_template);
            }
        }
        return parent::item_as_html($wp_post);
    }
}
