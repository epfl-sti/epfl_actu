<?php

/**
 * "Actu" custom post type and taxonomy.
 *
 * For each entry in actus.epfl.ch that the WordPress administrators
 * are interested in, there is a local copy as a post inside the
 * WordPress database. This allows e.g. putting actus news into the
 * newsletter or using the full-text search on them.
 *
 * The "Actu" custom post type integrates with WP Subtitles, if
 * installed (https://wordpress.org/plugins/wp-subtitle/). Note that
 * only Actu items fetched *after* WP Subtitles is installed, can get
 * a subtitle.
 */

namespace EPFL\WS\Actu;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/base-classes.inc");

require_once(dirname(__FILE__) . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

/**
 * Object model for Actu streams
 *
 * One stream corresponds to one so-called "term" in the
 * 'epfl-actu-channel' WordPress taxonomy. Each stream has an API URL
 * from which news are continuously fetched.
 */
class ActuStream extends \EPFL\WS\Base\APIChannelTaxonomy
{
    static function get_taxonomy_slug ()
    {
        return 'epfl-actu-channel';
    }

    static function get_term_meta_slug ()
    {
        return "epfl_actu_channel_api_url";
    }

    static function get_post_class ()
    {
        return Actu::class;
    }
}

/**
 * Object model for Actu posts
 *
 * There is one instance of the Actu class for every unique piece of
 * news (identified by the "news_id" and "translation_id" API fields,
 * and materialized as a WordPress "post" object of post_type ==
 * 'epfl-actu').
 */
class Actu extends \EPFL\WS\Base\APIChannelPost
{
    static function get_post_type ()
    {
        return 'epfl-actu';
    }

    static function get_auto_category_class () {
        return ActuCategory::class;
    }

    static function get_auto_category_id_key () {
        return "news_category_id";
    }

    static function get_api_id_key ()
    {
        return "news_id";
    }

    static function get_image_url_key ()
    {
        return "news_thumbnail_absolute_url";
    }

    /**
     * Overridden to retain video metadata and subtitle, if any
     */
    protected function _update_post_meta ($api_result)
    {
        parent::_update_post_meta($api_result);
        foreach (["video", "news_has_video",
                  "visual_and_thumbnail_description"]
                 as $keep_this_as_meta)
        {
            if ($api_result[$keep_this_as_meta]) {
                $this->_post_meta[$keep_this_as_meta] = $api_result[$keep_this_as_meta];
            }
        }

        $youtube_id = $this->_extract_youtube_id($api_result);
        if ($youtube_id) {
            $this->_post_meta["youtube_id"] = $youtube_id;
        }

        // Support for WP Subtitle plugin
        if (class_exists("WPSubtitle")) {
            $subtitle = $this->extract_subtitle($api_result);
            if ($subtitle && $subtitle !== $api_result["title"]) {
                // Like private function get_post_meta_key() in subtitle.php
                $subtitle_meta_key = apply_filters( 'wps_subtitle_key', 'wps_subtitle', $this->ID);
                $this->_post_meta[$subtitle_meta_key] = $subtitle;
            }
        }
    }

    function get_youtube_id ()
    {
        return $this->_get_post_meta()["youtube_id"];
    }

    protected function _update_image_meta ($api_result)
    {
        parent::_update_image_meta($api_result);
        $youtube_id = $this->_extract_youtube_id($api_result);
        if ($youtube_id) {
            // The "right" thumbnail for a YouTube video is the one
            // YouTube serves - See also
            // https://stackoverflow.com/a/2068371/435004
            $this->_set_thumbnail_url(sprintf(
                "https://img.youtube.com/vi/%s/default.jpg",
                $youtube_id));
        }
    }

    private function _extract_youtube_id ($api_result)
    {
        $matched = array();
        if (preg_match('#youtube.com/embed/([^/?]+)#', $api_result["video"],
                       $matched)) {
            return $matched[1];
        }
    }

    protected function _get_excerpt ($api_result)
    {
        return $api_result["subtitle"];
    }
    protected function _get_content ($api_result)
    {
        return $api_result["text"];
    }

    /**
     * Extract a subtitle from the API's excerpt.
     *
     * The field named "subtitle" is generally unfit for use as a
     * subtitle in the WordPress sense — It is more like post_excerpt.
     * However, some subtitles on actu.epfl.ch do start with a short
     * sentence followed with a <br />. If that is the case, return it.
     */
    private function extract_subtitle ($api_result) {
        $matched = array();
        $max_subtitle_length = 80;
        if (preg_match("/^(.{1,$max_subtitle_length})<br/", $api_result["subtitle"], $matched)) {
            return trim($matched[1]);
        } elseif (preg_match("/^<p>(.{1,$max_subtitle_length})<\/p>/", $api_result["subtitle"], $matched)) {
            return trim($matched[1]);
        } else {
            return null;
        }
    }
}

/**
 * Configuration UI and WP callbacks for the actu stream taxonomy
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuStreamController extends \EPFL\WS\Base\APIChannelTaxonomyController
{
    static function get_taxonomy_class () {
        return ActuStream::class;
    }

    static function get_human_labels ()
    {
        return array(
            // These are for regster_taxonomy
            'name'              => __x( 'News Channels', 'taxonomy general name'),
            'singular_name'     => __x( 'News Channel', 'taxonomy singular name'),
            'search_items'      => ___( 'Search News Channels'),
            'all_items'         => ___( 'All News Channels'),
            'edit_item'         => ___( 'Edit News Channel'),
            'update_item'       => ___( 'Update News Channel'),
            'add_new_item'      => ___( 'Add News Channel'),
            'new_item_name'     => ___( 'New Channel Name'),
            'menu_name'         => ___( 'News Channels'),

            // These are internal to APIChannelTaxonomyController
            'url_legend'        => ___('Actu Channel API URL'),
            'url_legend_long'   => ___("Source URL of the JSON data. Use <a href=\"https://wiki.epfl.ch/api-rest-actu-memento/actu\" target=\"_blank\">actu-doc</a> for details.")
        );
    }

    static function get_placeholder_api_url ()
    {
        return "https://actu.epfl.ch/api/jahia/channels/sti/news/en/?format=json";
    }
}

/**
 * WP configuration and callbacks for the EPFL Actu post type
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuController extends \EPFL\WS\Base\APIChannelPostController
{
    static function get_taxonomy_class () {
        return ActuStream::class;
    }

    static function filter_register_post_type (&$settings) {
        $settings["menu_icon"] = 'dashicons-megaphone';
        $settings["menu_position"] = 41;
    }

    static function get_human_labels ()
    {
        return array(
            // For register_post_type:
            'name'               => __x( 'EPFL News', 'post type general name' ),
            'singular_name'      => __x( 'EPFL News', 'post type singular name' ),
            'menu_name'          => __x( 'EPFL News', 'admin menu' ),
            'name_admin_bar'     => __x( 'EPFL News', 'add new on admin bar' ),
            'view_item'          => ___( 'View EPFL News Item' ),
            'all_items'          => ___( 'All EPFL News for this site' ),
            'search_items'       => ___( 'Search News' ),
            'not_found'          => ___( 'No news found.' ),
            'not_found_in_trash' => ___( 'No news found in Trash.' ),

            // Others:
            'description'        => ___( 'EPFL News from news.epfl.ch' )
        );
    }

    static function hook ()
    {
        parent::hook();
        add_action('admin_init', array(get_called_class(), 'make_subtitles_readonly_in_admin'), 0);
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
     * Overloaded to show an actual embedded video in the admin area, yow!
     */
    static function render_thumbnail_column ($actu, $img)
    {
        if ($actu->get_youtube_id()) {
            echo '<object style="width:160px;height:89px;float: none; clear: both; margin: 2px auto;" data="https://www.youtube.com/embed/'.$actu->get_youtube_id().'"></object>';
            printf("<p><a href=\"https://youtu.be/%s\">YouTube link</a></p>", $actu->get_youtube_id());
        } else {
            parent::render_thumbnail_column ($actu, $img);
        }
    }
}

/**
 * A standard WordPress category that is auto-assigned to Actu elements.
 */
class ActuCategory extends \EPFL\WS\Base\APIAutoCategory
{
    static function get_post_class ()
    {
        return Actu::class;
    }

    static function get_term_meta_slug ()
    {
        return "epfl_actu_category_id";
    }

    static function get_api_category_names () {
        // https://actus.epfl.ch/api/v1/categories/
        return array(
            "1" => __x("EPFL",        "Actu API category"),
            "2" => __x("Education",   "Actu API category"),
            "3" => __x("Research",    "Actu API category"),
            "4" => __x("Innovation",  "Actu API category"),
            "5" => __x("Campus Life", "Actu API category")
        );
    }
}

/**
 * WP configuration and callbacks for categories of Actus
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class ActuCategoryController extends \EPFL\WS\Base\APIAutoCategoryController
{
    static function get_model_class ()
    {
        return ActuCategory::class;
    }

    static function get_human_labels ()
    {
        return array(
            "category_name_label" => ___("Actu's category name"),
            "purpose_explanation" => ___("Setting this will cause matching posts from news.epfl.ch to automatically get classified into this category."),
            "column_title"        => ___("Actu category")
        );
    }

    static function get_wp_admin_css_class ()
    {
        return "actu-description-wrap";
    }
}

ActuStreamController::hook();
ActuController::hook();
ActuCategoryController::hook();
