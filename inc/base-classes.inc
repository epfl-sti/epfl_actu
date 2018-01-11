<?php

/**
 * A set of abstract base classes for Actu, Memento and more.
 */

namespace EPFL\WS\Base;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

use WP_Query;

require_once(dirname(__FILE__) . "/i18n.inc");

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
     * @return The object class for WP posts this APIChannelTaxonomy.
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
     *         (e.g. the API URL) to objects of this class in the
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

    function as_category ()
    {
        return $this->ID;
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

    static function hook ()
    {
        add_action('init', array(get_called_class(), '_do_register_taxonomy'));
    }

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
        ?><tr class="form-field actu-channel-url-wrap">
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

    var $ID;
    var $api_id;
    var $translation_id;

    /**
     * Private constructor — Call @link get, @link get_or_create
     * or @link sync instead
     */
    function __construct ($id)
    {
        $this->ID = $id;
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
            $that = new $theclass($id);
        } else {
            $that = new $theclass($results[0]->ID);
        }
        $that->api_id = $api_id;
        $that->translation_id = $translation_id;
        return $that;
    }

    /**
     * Retrieve one API channel post, only if it does exist.
     *
     * @return an instance of this class or null.
     */
    static function get ($post_or_post_id)
    {
        $theclass = get_called_class();
        if (is_object($post_or_post_id)) {
            if ($post_or_post_id->post_type !== $theclass::get_post_type()) return;
            $post_id = $post_or_post_id->ID;
        } else {
            $post_id = $post_or_post_id;
            if (get_post_type($post_id) !== $theclass::get_post_type()) return;
        }
        $that = new $theclass($post_id);
        if (is_object($post_or_post_id)) {
            $that->_wp_post = $post_or_post_id;
        }
        return $that;
    }

    /**
     * Create or update an instance from $api_result
     *
     * @param $api_result One of the API results returned by
     *        @link ActuAPIClient#fetch
     *
     * @return The object instance that corresponds to the primary keys
     *         present in $api_result (in the sense of @link get_or_create)
     */
    static function sync ($api_result) {
        $that = static::get_or_create($api_result[static::get_api_id_key()],
                                      $api_result["translation_id"]);

        $that->_init_post_meta();

        // "absolute_slug" is common to both APIs
        $that->_post_meta["absolute_slug"] = $api_result["absolute_slug"];

        $that->_update_post_meta($api_result);
        $that->_post_meta[self::THUMBNAIL_META] = $that->_update_image_meta($meta);
        // Polylang
        if (function_exists("pll_set_post_language")) {
            pll_set_post_language($that->ID, $api_result["language"]);
        }

        $update_post_args = array(
            "ID"            => $that->ID,
            "post_type"     => $that->get_post_type(),
            "post_title"    => $that->_get_title($api_result),
            "post_content"  => $that->_get_content($api_result),
            "meta_input"    => $that->_post_meta
        );

        $excerpt = $that->_get_excerpt($api_result);
        if ($excerpt) {
            $update_post_args["post_excerpt"] = $excerpt;
        }

        wp_update_post($update_post_args);

        $add_in_categories = $that->_get_auto_categories($api_result);
        if (count($add_in_categories)) {
            wp_set_post_categories($that->ID, $add_in_categories,
                                   /* $append = */ true);
        }
        return $that;
    }


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
    protected function _update_post_meta ($api_result) {
        $this->_post_meta["absolute_slug"] = $api_result["absolute_slug"];
    }

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

    function wp_post ()
    {
        if (! $this->_wp_post) {
            $this->_wp_post = get_post($this->ID);
        }
        return $this->_wp_post;
    }

    const THUMBNAIL_META  = "epfl_external_thumbnail";
    const MAX_HEIGHT_META = "epfl_external_img_max_height";
    const MAX_WIDTH_META  = "epfl_external_img_max_width";

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
     * The base class obtains the thumbnail from the key named by
     * @link get_image_url_key, and then probes the maximum image size
     * server-side (see @link get_image_url for details on this
     * feature of EPFL Actu and Memento). Subclasses may decide to
     * override the method, and even forego calling the parent::
     * altogether, if they know better what image to use (e.g. YouTube
     * thumbnail for Actus with videos).
     *
     * @return The URL of the main (thumbnail) image for this post.
     *         Caller will take care of stashing that into
     *         $this->_post_meta[THUMBNAIL_META], therefore
     *         _update_image_meta() need not worry about it.
     */
    protected function _update_image_meta ($api_result)
    {
        $image_url = $api_result[$this->get_image_url_key()];
        if (! $image_url) return;
        $max_size = get_image_size($this->get_image_url("8000x6000"));
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
        $meta = $this->_get_post_meta();
        $url = $meta[self::THUMBNAIL_META];
        if (! $url) return null;
        if (! $size) return $url;
        $matched = array();
        if (preg_match("/^(.*)\/(\d+x\d+)\.([a-zA-z]{1,6})$/", $url, $matched)) {
            return sprintf("%s/%s.%s", $matched[1], $size, $matched[3]);
        } else {
            return $url;
        }
    }

    function get_permalink ()
    {
        $meta = $this->_get_post_meta();
        return $meta["absolute_slug"];
    }

}
