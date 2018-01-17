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

require_once(dirname(__FILE__) . "/cron.inc");
use function \EPFL\WS\run_every;

require_once(dirname(__FILE__) . "/image-size.inc");
use function \EPFL\WS\get_image_size;

use \WPPrometheusExporter;  // In a plug-in - No harm if it doesn't exist

function _debug ($msg)
{
    // error_log($msg);
}

/**
 * Abstract base classes for taxonomies whose terms correspond to an API URL.
 *
 * A "taxonomy" is a complicated word for a way to organize WordPress
 * posts together. Actu and Memento entries are grouped by "channels",
 * i.e. the feed they come from. Channels have names and host suitable
 * metadata, i.e. an API URL from which news, events etc. are
 * continuously fetched.
 *
 * Instances of the clas represent one so-called "term" in one of the
 * EPFL-WS taxonomies such as "epfl-actu-channel" (for the ActuStream
 * subclass) or "epfl-memento-channel" (MementoStream subclass).
 */
abstract class APIChannelTaxonomy
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
     * @return A slug (unique keyword) used to associate metadata
     *         (here, the API URL) to objects of this class in the
     *         WordPress database
     */
    static abstract function get_term_meta_slug ();

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

    function register_prometheus_metrics ()
    {
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
 * Configuration UI and WP callbacks for a APIChannelTaxonomy class.
 *
 * A taxonomy is pretty much an end-user-invisible concept so much of the
 * responsibility of this class is towards wp-admin. This class has
 * no instances.
 */
abstract class APIChannelTaxonomyController
{
    /**
     * @return The @link APIChannelTaxonomy subclass this controller serves.
     */
    abstract static function get_taxonomy_class ();

    /**
     * @return An URL to show as an example in the "URL" field of a new
     * APIChannelTaxonomy instance being created in wp-admin
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
     *         keys are used by APIChannelTaxonomyController directly:
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

        add_action('plugins_loaded', function () {
            if (! class_exists("WPPrometheusExporter")) return;
            WPPrometheusExporter::register_metric(
                'epfl_ws_last_sync_success',
                array(
                    'help' => 'Timestamp of last successful sync from API'
                ));
            WPPrometheusExporter::register_metric(
                'epfl_ws_sync_duration',
                array(
                    'help' => 'Duration of last sync from API (successful or not)',
                    'has_timestamp' => true
                )
            );
            WPPrometheusExporter::register_metric(
                'epfl_ws_sync_success',
                array(
                    'type' => 'gauge',
                    'help' => '0 for error, 1 for success',
                    'has_timestamp' => true
                )
            );
        });

        $taxonomy_class = static::get_taxonomy_class();
        run_every(600, function () use ($taxonomy_class) {
            foreach ($taxonomy_class::get_all() as $stream) {
                $stream_name = $stream->as_wp_term()->name;
                $stream_slug = $stream->as_wp_term()->slug;
                error_log("Syncing Actu stream $stream_name ($stream_slug)");
                
                $started = microtime(true);
                try {
                    $stream->sync();
                    $failed = null;
                } catch (Exception $e) {
                    $failed = $e;
                }
                $duration = microtime(true) - $started;

                error_log(sprintf(
                    "Syncing Actu stream %s (%s), duration: %.03ds, status: %s",
                    $stream_name,
                    $stream_slug,
                    $duration,
                    ($failed ? $failed->getMessage() : "OK")));

                if (! class_exists("WPPrometheusExporter")) continue;
                $labels = array(
                    'kind' => $taxonomy_class::get_taxonomy_slug(),
                    'slug' => $stream_slug
                );
                (new WPPrometheusExporter('epfl_ws_sync_duration', $labels))
                    ->update($duration);
                (new WPPrometheusExporter('epfl_ws_sync_success', $labels))
                    ->update($failed ? 0 : 1);
                if (! $failed) {
                    (new WPPrometheusExporter('epfl_ws_last_sync_success', $labels))
                        ->update($started);
                }
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
 * Abstract base class for Actu and Memento posts
 *
 * There is one instance of this class for every unique piece of news
 * (identified by the "news_id"/"event_id" and "translation_id" API
 * fields, and materialized as a WordPress "post" object of post_type
 * == 'epfl-actu' or 'epfl-memento').
 */

abstract class APIChannelPost
{
    /**
     * @return the post_type slug for instances of this class
     */
    static abstract function get_post_type ();

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

    /**
     * Populated piecemeal at @link sync time by the various
     * _update_* and _set_* methods; fetched all at once by
     * @link _get_post_meta
     */
    protected $_post_meta;

    /**
     * Private constructor — Call @link get, @link get_or_create
     * or @link sync instead
     */
    function __construct ($id, $api_id, $translation_id)
    {
        $this->ID             = $id;
        $this->api_id         = $api_id;
        $this->translation_id = $translation_id;
    }

    /**
     * Retrieve one post per its primary key components.
     *
     * If the corresponding post does not exist in-database, it will
     * be created with no contents besides the `meta_input` made up
     * of $api_id and $translation_id (but see @link update).
     */
    static function get_or_create ($api_id, $translation_id)
    {
        $theclass = get_called_class();
        $search_query = new WP_Query(array(
           'post_type'  => $theclass::get_post_type(),
           'meta_query' => array(
               'relation' => 'AND',
                array(
                   'key'     => 'translation_id',
                   'value'   => $translation_id,
                   'compare' => '='
               ),
               array(
                   'relation' => 'OR',
                   array(
                       'key'     => 'api_id',
                       'value'   => $api_id,
                       'compare' => '='
                   ),
                   // For backward compatibility when Actu was the sole
                   // class using this code:
                   array(
                       'key'     => 'news_id',
                       'value'   => $api_id,
                       'compare' => '='
                   ),
               ),
           )
        ));
        $results = $search_query->get_posts();
        if (0 === sizeof($results)) {
            $id = wp_insert_post(array(
                "post_type" => $theclass::get_post_type(),
                "post_status" => "publish",
                "meta_input" => array(
                    "api_id" => $api_id,
                    "translation_id" => $translation_id
                )), true);
        } else {
            $id = $results[0]->ID;
        }

        return new $theclass($id, $api_id, $translation_id);
    }

    /**
     * Retrieve one API channel post, only if it does exist.
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
     *         present in $api_result (in the sense of @link get_or_create)
     */
    static function sync ($api_result)
    {
        $that = static::get_or_create($api_result[static::get_api_id_key()],
                                      $api_result["translation_id"]);
        $that->_sync($api_result);
        return $that;
    }

    public function _sync($api_result)
    {
        $this->_init_post_meta();

        // "absolute_slug" is common to both APIs
        $this->_post_meta["absolute_slug"] = $api_result["absolute_slug"];

        $this->_update_post_meta($api_result);
        $this->_update_image_meta($api_result);
        // Polylang
        if (function_exists("pll_set_post_language")) {
            pll_set_post_language($this->ID, $this->_get_language($api_result));
        }

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

        $auto_categories = $this->get_auto_category_class()::find(
            $api_result[$this->get_auto_category_id_key()],
            /* $lang_hint = */ $this->_get_language($api_result));
        $auto_category_ids = array_map(function($cat) { return $cat->ID(); }, $auto_categories);
        _debug(sprintf("Appending %s to post %d", var_export($auto_category_ids, true), $this->ID));
        wp_set_post_categories(
            $this->ID,
            array_map(function($cat) { return $cat->ID(); }, $auto_categories),
            /* $append = */ true);
    }

    function _get_language ($api_result) {
        return ($api_result["language"] ? $api_result["language"] :
                $api_result["lang"]);
    }

    function wp_post ()
    {
        if (! $this->_wp_post) {
            $this->_wp_post = get_post($this->ID);
        }
        return $this->_wp_post;
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
 * Configuration UI and WP callbacks for an APIChannelPost class.
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
abstract class APIChannelPostController
{
    /**
     * Get the labels to display in various places in the UI.
     *
     * @return An associative array whose keys are i18n-neutral
     *         keywords and whose values are translation strings. This
     *         array gets passed as-is as the 'labels' value to
     *         WordPress' @link register_post_type, and therefore ought
     *         to contain like-named keys. Additionally the following
     *         keys are used by APIChannelTaxonomyController directly:
     *
     * - description: The post type description for wp_admin (passed
     *                as the `"description"` field to `register_post_type`)
     *
     */
    abstract static function get_human_labels ();

    /**
     * @return The @link APIChannelTaxonomy subclass this controller serves.
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

        $main_plugin_file = dirname(dirname(__FILE__)) . "/EPFL-ws.php";
        register_activation_hook($main_plugin_file, array(get_called_class(), "register_caps"));
        register_deactivation_hook($main_plugin_file, array(get_called_class(), "deregister_caps"));

        // Behavior of epfl-ws posts on the main site
        add_filter("post_thumbnail_html",
                   array(get_called_class(), "filter_post_thumbnail_html"), 10, 5);
        add_filter("post_type_link",
                   array(get_called_class(), "filter_post_link"), 10, 4);

        // Behavior of epfl-ws posts in the wp-admin area
        add_action('admin_init', array(get_called_class(), 'make_subtitles_readonly_in_admin'), 0);
        static::add_thumbnail_column();
        static::configure_rendering_in_edit_form();
        add_action("admin_enqueue_scripts", array(get_called_class(), "editor_css"));

        // Behavior of epfl-ws posts in search results
        add_filter('pre_get_posts', array(get_called_class(), "pre_get_posts"));
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
                    // One is not normally supposed to delete posts
                    // manually — This is just an escape hatch in case
                    // an APIChannelTaxonomy was deleted and
                    // referential integrity was breached.
                    'delete_post'          => 'edit_epfl_ws_posts',
                    'delete_posts'         => 'edit_epfl_ws_posts',
                    'delete_private_posts' => 'edit_epfl_ws_posts',
                    'delete_others_posts'  => 'edit_epfl_ws_posts',
                ),
                'has_archive'        => true,
                'hierarchical'       => false,
                'taxonomies'         => array($taxonomy_slug, 'category'),
                'menu_position'      => 24,
                'menu_icon'          => $menu_icon,
                'supports'           => array('wps_subtitle')
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
     * Make room for a column that shows the thumbnails and some
     * details
     */
    static function add_thumbnail_column ()
    {
        $this_class = get_called_class();
        $model_class = $this_class::get_model_class();
        $post_type = $model_class::get_post_type();
        add_action( sprintf('manage_%s_posts_columns', $post_type),
                    function ($columns) {
                        // https://stackoverflow.com/a/3354804/435004
                        return array_merge(
                            array_slice($columns, 0, 1, true),
                            array('thumbnail' => ___( 'Thumbnail' )),
                            array_slice($columns, 1, count($columns) - 1, true));
                    });
        add_action(
            sprintf('manage_%s_posts_custom_column', $post_type),
            function ($column, $post_id) use ($this_class, $model_class) {
                if ($column !== 'thumbnail') return;
                $img = get_the_post_thumbnail($post_id);
                if (! $img) return;

                $epfl_post = $model_class::get($post_id);
                if (! $epfl_post) return;

                $this_class::render_thumbnail_column($epfl_post, $img);
            }, 10, 2);
    }

    /**
     * Render an EPFL post as a thumbnail in an additional column in
     * the wp-admin list view
     *
     * The base class appends size informations below $img. Subclasses
     * may override this to render the thumbnail differently.
     *
     * @param $epfl_post An instance of the class returned by @link
     * get_model_class
     *
     * @param $img HTML code for the thumbnail (as returned by
     * Wordpress' @link get_the_post_thumbnail)
     */
    static function render_thumbnail_column ($epfl_post, $img)
    {
        echo $img;
        if ($orig_size = $epfl_post->get_max_size()) {
            printf("<p>Size: %dx%d</p>", $orig_size["width"], $orig_size["height"]);
        }
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
        // Add back the subtitle column:
        add_filter( 'manage_edit-' . $post_type . '_columns', array( 'WPSubtitle_Admin', 'manage_subtitle_columns' ) );
        add_action( 'manage_' . $post_type . '_posts_custom_column', array( 'WPSubtitle_Admin', 'manage_subtitle_columns_content' ), 10, 2 );
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
    <img class="epfl-ws-thumbnail" src="<?php echo $epfl_post->get_image_url() ?>"/>
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
