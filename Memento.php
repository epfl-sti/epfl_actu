<?php

/**
 * "Memento" custom post type and taxonomy.
 *
 * For each event in memento.epfl.ch in channels that the WordPress
 * administrators are interested in, there is a local copy as a post
 * inside the WordPress database. This allows e.g. putting memento
 * events into the newsletter or using the full-text search on them.
 */

namespace EPFL\WS\Memento;


if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/base-classes.inc");

require_once(dirname(__FILE__) . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

/**
 * Object model for Memento streams
 *
 * One stream corresponds to one so-called "term" in the
 * 'epfl-memento-channel' WordPress taxonomy. Each stream has an API URL
 * from which events are continuously fetched.
 */
class MementoStream extends \EPFL\WS\Base\APIChannelTaxonomy
{
    static function get_taxonomy_slug ()
    {
        return 'epfl-memento-channel';
    }

    static function get_term_meta_slug ()
    {
        return "epfl_memento_channel_api_url";
    }

    static function get_post_class ()
    {
        return Memento::class;
    }
}

/**
 * Object model for Memento posts
 *
 * There is one instance of the Memento class for every unique event
 * (identified by the "event_id" and "translation_id" API fields, and
 * materialized as a WordPress "post" object of post_type ==
 * 'epfl-memento').
 */
class Memento extends \EPFL\WS\Base\APIChannelPost
{
    static function get_post_type ()
    {
        return 'epfl-memento';
    }

    static function get_auto_category_class () {
        return MementoCategory::class;
    }

    static function get_auto_category_id_key () {
        return "event_category_id";
    }

    static function get_api_id_key ()
    {
        return "event_id";
    }

    static function get_image_url_key ()
    {
        return "event_visual_absolute_url";
    }

    protected function _get_content ($api_result)
    {
        return $api_result["description"];
    }

    public function get_ical_link ()
    {
        return sprintf("https://memento.epfl.ch/event/export/%d", $this->translation_id);
    }

    /**
     * Overridden to retain video metadata and subtitle, if any
     */
    protected function _update_post_meta ($api_result)
    {
        parent::_update_post_meta($api_result);
        foreach (["event_start_date", "event_end_date",
                  "event_start_time", "event_end_time",
                  "event_theme", "event_speaker",
                  "event_place_and_room", "event_url_place_and_room",
                  "event_canceled_reason"]
                 as $keep_this_as_meta)
        {
            if ($api_result[$keep_this_as_meta]) {
                $this->_post_meta[$keep_this_as_meta] = $api_result[$keep_this_as_meta];
            }
        }
        foreach (["event_is_internal", "event_canceled"]
        as $keep_this_as_bool_meta) {
            $this->_post_meta[$keep_this_as_bool_meta] = (
                strtolower($api_result[$keep_this_as_bool_meta]) === "true");
        }
    }
}

/**
 * Configuration UI and WP callbacks for the memento stream taxonomy
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class MementoStreamController extends \EPFL\WS\Base\APIChannelTaxonomyController
{
    static function get_taxonomy_class () {
        return MementoStream::class;
    }

    static function get_human_labels ()
    {
        return array(
            // These are for regster_taxonomy
            'name'              => __x( 'Event Channels', 'taxonomy general name'),
            'singular_name'     => __x( 'Event Channel', 'taxonomy singular name'),
            'search_items'      => ___( 'Search Event Channels'),
            'all_items'         => ___( 'All Event Channels'),
            'edit_item'         => ___( 'Edit Event Channel'),
            'update_item'       => ___( 'Update Event Channel'),
            'add_new_item'      => ___( 'Add Event Channel'),
            'new_item_name'     => ___( 'New Channel Name'),
            'menu_name'         => ___( 'Event Channels'),

            // These are internal to APIChannelTaxonomyController
            'url_legend'        => ___('Memento Channel API URL'),
            'url_legend_long'   => ___("Source URL of the JSON data. Use <a href=\"https://wiki.epfl.ch/api-rest-actu-memento/memento\" target=\"_blank\">memento-doc</a> for details.")
        );
    }

    static function get_placeholder_api_url ()
    {
        return "https://memento.epfl.ch/api/jahia/mementos/sti/events/en/?format=json";
    }
}

/**
 * WP configuration and callbacks for the EPFL Memento post type
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class MementoController extends \EPFL\WS\Base\APIChannelPostController
{
    static function get_taxonomy_class () {
        return MementoStream::class;
    }

    static function filter_register_post_type (&$settings) {
        $settings["menu_icon"] = 'dashicons-calendar';
        $settings["menu_position"] = 42;
    }

    static function get_human_labels ()
    {
        return array(
            // For register_post_type:
            'name'               => __x( 'EPFL Events', 'post type general name' ),
            'singular_name'      => __x( 'EPFL Events', 'post type singular name' ),
            'menu_name'          => __x( 'EPFL Events', 'admin menu' ),
            'name_admin_bar'     => __x( 'EPFL Events', 'add new on admin bar' ),
            'view_item'          => ___( 'View EPFL Event' ),
            'all_items'          => ___( 'All EPFL Events for this site' ),
            'search_items'       => ___( 'Search Events' ),
            'not_found'          => ___( 'No events found.' ),
            'not_found_in_trash' => ___( 'No events found in Trash.' ),

            // Others:
            'description'        => ___( 'EPFL events from events.epfl.ch' )
        );
    }
}

/**
 * A standard WordPress category that is auto-assigned to Memento elements.
 */
class MementoCategory extends \EPFL\WS\Base\APIAutoCategory
{
    static function get_post_class ()
    {
        return Memento::class;
    }

    static function get_term_meta_slug ()
    {
        return "epfl_memento_category_id";
    }

    static function get_api_category_names () {
        // https://memento.epfl.ch/api/v1/categories/
        return array(
            "1"  => __x("Conferences - Seminars",                 "Memento API category"),
            "2"  => __x("Management Board meetings",              "Memento API category"),
            "4"  => __x("Miscellaneous",                          "Memento API category"),
            "5"  => __x("Exhibitions",                            "Memento API category"),
            "6"  => __x("Movies",                                 "Memento API category"),
            "7"  => __x("Celebrations",                           "Memento API category"),
            "8"  => __x("Inaugural Lectures - Honorary Lectures", "Memento API category"),
            "9"  => __x("Cultural events",                        "Memento API category"),
            "10" => __x("Sporting events",                        "Memento API category"),
            "12" => __x("Thesis defenses",                        "Memento API category"),
            "13" => __x("Academic Calendar",                      "Memento API category"),
            "15" => __x("Internal trainings",                     "Memento API category"),
            "16" => __x("Call for proposal",                      "Memento API category"),
            "17" => __x("Deadline",                               "Memento API category")
        );
    }
}

/**
 * WP configuration and callbacks for categories of Memento
 *
 * This is a "pure static" class; no instances are ever constructed.
 */
class MementoCategoryController extends \EPFL\WS\Base\APIAutoCategoryController
{
    static function get_model_class ()
    {
        return MementoCategory::class;
    }

    static function get_human_labels ()
    {
        return array(
            "category_name_label" => ___("Memento category name"),
            "purpose_explanation" => ___("Setting this will cause matching posts from events.epfl.ch to automatically get classified into this category."),
            "column_title"        => ___("Memento category")
        );
    }

    static function get_wp_admin_css_class ()
    {
        return "memento-description-wrap";
    }
}

MementoStreamController::hook();
MementoController::hook();
MementoCategoryController::hook();
