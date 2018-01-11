<?php

/**
 * A set of abstract base classes for Actu, Memento and more.
 */

namespace EPFL\WS\Base;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

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
        foreach ($client->fetch() as $APIelement) {
            $post_class = $this->get_post_class();
            $epfl_post = $post_class::get_or_create($APIelement["news_id"], $APIelement["translation_id"]);
            $epfl_post->update($APIelement);
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
