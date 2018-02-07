<?php

/**
 * Model and controller for an EPFL course out of IS-Academia
 */

namespace EPFL\WS\ISAcademia;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(__DIR__ . "/inc/base-classes.inc");
use \EPFL\WS\Base\Post;
use \EPFL\WS\Base\StreamPostController;
use \EPFL\WS\Base\StreamTaxonomy;
use \EPFL\WS\Base\StreamTaxonomyController;

require_once(__DIR__ . "/inc/auto-fields.inc");
use \EPFL\WS\AutoFields;
use \EPFL\WS\AutoFieldsController;

require_once(__DIR__ . "/inc/ISAcademiaAPI.inc");

require_once(__DIR__ . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

require_once(dirname(__FILE__) . "/inc/batch.inc");
use function \EPFL\WS\run_every;
use \EPFL\WS\BatchTask;

/**
 * A taxonomy of courses obtained out of IS-Academia
 *
 * A "taxonomy" is a complicated word for a way to organize WordPress
 * posts together. An instance of CourseTaxonomy represents a so-called WordPress
 * "term," which represents one stream of courses excerpted from IS-Academia.
 * The term object hosts persistent metadata consisting of one URL of the form
 * https://people.epfl.ch/cgi-bin/getCours?unit=SEL-ENS, which constitutes the
 * starting point of the scraping process that auto-creates the Course objects.
 * This URL is editable in the wp-admin area (see @link CourseTaxonomyController)
 */
class CourseTaxonomy extends StreamTaxonomy
{
    static function get_post_class ()
    {
        return Course::class;
    }


    static function get_taxonomy_slug ()
    {
        return 'epfl-is-academia-channel';
    }

    static function get_term_meta_slug ()
    {
        return "epfl_is_academia_url";
    }

    function sync ()
    {
        require_once (__DIR__ . "/inc/ISAcademiaAPI.inc");
        foreach (parse_getCours($this->get_url()) as $course_url) {
            $course = Course::get_or_create_by_url($course_url);
            $course->sync();
            $this->set_ownership($course);
        }
    }
}

class CourseTaxonomyController extends StreamTaxonomyController
{
    static function get_taxonomy_class () {
        return CourseTaxonomy::class;
    }

    static function get_human_labels ()
    {
        return array(
            // These are for register_taxonomy
            'name'              => __x( 'ISAcademia Feeds', 'taxonomy general name'),
            'singular_name'     => __x( 'ISAcademia Feed', 'taxonomy singular name'),
            'search_items'      => ___( 'Search ISAcademia Feeds'),
            'all_items'         => ___( 'All ISAcademia Feeds'),
            'edit_item'         => ___( 'Edit ISAcademia Feed'),
            'update_item'       => ___( 'Update ISAcademia Feed'),
            'add_new_item'      => ___( 'Add ISAcademia Feed'),
            'new_item_name'     => ___( 'New ISAcademia Feed'),
            'menu_name'         => ___( 'ISAcademia Feeds'),

            // These are internal to StreamTaxonomyController
            'url_legend'        => ___("ISAcademia starting URL"),
            'url_legend_long'   => ___("EPFL-WS will start scraping courses at this URL.")
        );
    }

    static function get_placeholder_api_url ()
    {
        return 'https://people.epfl.ch/cgi-bin/getCours?unit=SEL-ENS';
    }
}

class Course extends Post
{
    const SLUG = "epfl-course";

    static function get_post_type ()
    {
        return self::SLUG;
    }

    const COURSE_HOMEURL_META = "epfl_isacademia_url";
    const UNIQUE_ID_META      = "epfl_isacademia_id";
    const CODE_META           = "epfl_isacademia_code";
    const YEAR_META           = "epfl_isacademia_year";
    const LANGUAGE_META       = "epfl_isacademia_language";

    static function get_or_create_by_url ($url)
    {
        return static::_get_or_create(array(
            self::COURSE_HOMEURL_META => $url
        ));
    }

    function sync ()
    {
        $course_data = parse_course_page($this->get_permalink());
        $meta_input = array(
            self::COURSE_HOMEURL_META   => $this->get_permalink(),
            self::UNIQUE_ID_META        => $course_data["id"],
            self::CODE_META             => $course_data["code"],
            self::YEAR_META             => $course_data["year"],
        );

        wp_update_post(array(
            "ID"            => $this->ID,
            "post_type"     => $this->get_post_type(),
            "post_title"    => $course_data["title"],
            "post_content"  => $course_data["summary"],
            "meta_input"    => $meta_input
        ));
        if (function_exists("pll_set_post_language")) {
            pll_set_post_language($this->ID, $course_data["language"]);
        }
        AutoFields::of(get_called_class())->append(array_keys($meta_input));
    }

    function get_permalink ()
    {
        $meta = self::COURSE_HOMEURL_META;
        if ($this->$meta) {
             return $this->$meta;
        } else {
             return get_post_meta($this->ID, self::COURSE_HOMEURL_META, true);
        }
    }
}

class CourseController extends StreamPostController
{
    static function get_taxonomy_class () {
        return CourseTaxonomy::class;
    }

    static function filter_register_post_type (&$settings) {
        $settings["menu_icon"] = 'dashicons-welcome-write-blog';
        $settings["menu_position"] = 51;
    }

    static function get_human_labels ()
    {
        return array(
            // For register_post_type:
            'name'               => __x( 'Courses', 'post type general name' ),
            'singular_name'      => __x( 'Course', 'post type singular name' ),
            'menu_name'          => __x( 'EPFL Courses', 'admin menu' ),
            'name_admin_bar'     => __x( 'Course', 'add new on admin bar' ),
            'add_new'            => __x( 'Add New', 'add new lab' ),
            'add_new_item'       => ___( 'Add New Course' ),
            'new_item'           => ___( 'New Course' ),
            'edit_item'          => ___( 'Edit Course' ),
            'view_item'          => ___( 'View Course' ),
            'all_items'          => ___( 'All Courses' ),
            'search_items'       => ___( 'Search Course' ),
            'not_found'          => ___( 'No courses found.' ),
            'not_found_in_trash' => ___( 'No courses found in Trash.' ),

            // Others:
            'description'        => ___( 'Courses out of ISAcademia' )
        );
    }
}

CourseTaxonomyController::hook();
CourseController::hook();
