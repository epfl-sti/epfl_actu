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

require_once(__DIR__ . "/inc/auto-fields.inc");
use \EPFL\WS\AutoFields;
use \EPFL\WS\AutoFieldsController;

require_once(__DIR__ . "/inc/ISAcademiaAPI.inc");

require_once(__DIR__ . "/inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;


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
class CourseTaxonomy {
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
            $this->url = get_term_meta( $this->ID, $this->get_term_meta_slug(), true);
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
        require_once (__DIR__ . "/inc/ISAcademiaAPI.inc");
        foreach (parse_getCours($this->get_url()) as $course_url) {
            $course = Course::get_or_create_by_url($course_url);
            $course->sync();
            $this->set_ownership($course);
        }
    }

    /**
     * Mark in the database that $course was found by
     * fetching from this ISAcademia feed.
     *
     * This is materialized by a relationship in the
     * wp_term_relationships SQL table, using the @link
     * wp_set_post_terms API.
     */
    function set_ownership($course)
    {
        $terms = wp_get_post_terms(
            $course->ID, $this->get_taxonomy_slug(),
            array('fields' => 'ids'));
        if (! in_array($this->ID, $terms)) {
            wp_set_post_terms($course->ID, array($this->ID),
                              $this->get_taxonomy_slug(),
                              true);  // Append
        }
    }
}

class CourseTaxonomyController
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), '_do_register_taxonomy'));

        add_action('plugins_loaded', function () {
            if (! class_exists("WPPrometheusExporter")) { return; }
        });
    }

    static function _do_register_taxonomy ()
    {
        $taxonomy_class = CourseTaxonomy::class;
        $taxonomy_slug = $taxonomy_class::get_taxonomy_slug();
        $post_class = $taxonomy_class::get_post_class();
        $post_slug = $post_class::get_post_type();
        register_taxonomy(
            $taxonomy_slug,
            array($post_slug),
            array(
                'hierarchical'      => false,
                'labels'            => array(
                    'name'              => __x( 'ISAcademia Feeds', 'taxonomy general name'),
                    'singular_name'     => __x( 'ISAcademia Feed', 'taxonomy singular name'),
                    'search_items'      => ___( 'Search ISAcademia Feeds'),
                    'all_items'         => ___( 'All ISAcademia Feeds'),
                    'edit_item'         => ___( 'Edit ISAcademia Feed'),
                    'update_item'       => ___( 'Update ISAcademia Feed'),
                    'add_new_item'      => ___( 'Add ISAcademia Feed'),
                    'new_item_name'     => ___( 'New ISAcademia Feed'),
                    'menu_name'         => ___( 'ISAcademia Feeds'),
                ),
                'show_ui'           => true,
                'show_admin_column' => true,
                'query_var'         => true,
                'capabilities'      => array(
                    // Cannot reassign ISAcademia sources from post edit screen:
                    'assign_terms' => '__NEVER_PERMITTED__',
                    // Default permissions apply for the other operations
                ),
                'rewrite'           => array( 'slug' => $taxonomy_slug ),
            ));
        add_action("${taxonomy_slug}_add_form_fields", array(get_called_class(), "create_feed_widget"));
        add_action( "${taxonomy_slug}_edit_form_fields", array(get_called_class(), "update_feed_widget"), 10, 2);
        add_action( "created_${taxonomy_slug}", array(get_called_class(), 'edited_feed'), 10, 2 );
        add_action( "edited_${taxonomy_slug}", array(get_called_class(), 'edited_feed'), 10, 2 );
    }

    static function create_feed_widget ($taxonomy)
    {
        self::render_feed_widget(array("placeholder" => static::get_placeholder_url(), "size" => 40, "type" => "text"));
    }

    static function update_feed_widget ($term, $unused_taxonomy_slug)
    {
        $taxonomy_class = CourseTaxonomy::class;
        $current_url = (new $taxonomy_class($term))->get_url();
        ?><tr class="form-field epfl-ws-isacademia-feed">
            <th scope="row">
                <label for="<?php echo self::FEED_WIDGET_URL_SLUG ?>">
                    <?php echo self::_get_short_field_description(); ?>
                </label>
            </th>
            <td>
                <input id="<?php echo self::FEED_WIDGET_URL_SLUG; ?>" name="<?php echo self::FEED_WIDGET_URL_SLUG; ?>" type="text" size="40" value="<?php echo $current_url; ?>" />
                <p class="description"><?php echo self::_get_long_field_description(); ?></p>
            </td>
        </tr><?php
    }

    const FEED_WIDGET_URL_SLUG = 'epfl_channel_url';

    function get_placeholder_url ()
    {
        return 'https://people.epfl.ch/cgi-bin/getCours?unit=SEL-ENS';
    }

    static function render_feed_widget ($input_attributes)
    {
      ?><div class="form-field term-wrap">
        <label for="<?php echo self::FEED_WIDGET_URL_SLUG ?>"><?php echo self::_get_short_field_description(); ?></label>
        <input id="<?php echo self::FEED_WIDGET_URL_SLUG ?>" name="<?php echo self::FEED_WIDGET_URL_SLUG ?>" <?php
           foreach ($input_attributes as $k => $v) {
               echo "$k=" . htmlspecialchars($v) . " ";
           }?> />
       </div><?php
    }

    static function edited_feed ($term_id, $tt_id)
    {
        $stream = new CourseTaxonomy($term_id);
        $stream->set_url($_POST[self::FEED_WIDGET_URL_SLUG]);
        $stream->sync();
    }

    static function _get_short_field_description ()
    {
        return ___("ISAcademia starting URL");
    }

    static function _get_long_field_description ()
    {
        return ___("EPFL-WS will start scraping courses at this URL.");
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
        $course_data = parse_course_page($this->get_url());
        $meta_input = array(
            self::COURSE_HOMEURL_META   => $this->get_url(),
            self::UNIQUE_ID_META        => $course_data["id"],
            self::CODE_META             => $course_data["code"],
            self::YEAR_META             => $course_data["year"],
             // TODO: Use Polylang instead.
            self::LANGUAGE_META         => $course_data["language"],
        );

        wp_update_post(array(
            "ID"            => $this->ID,
            "post_type"     => $this->get_post_type(),
            "post_title"    => $course_data["title"],
            "post_content"  => $course_data["summary"],
            "meta_input"    => $meta_input
        ));
        AutoFields::of(get_called_class())->append(array_keys($meta_input));
    }

    function get_url ()
    {
        $meta = self::COURSE_HOMEURL_META;
        if ($this->$meta) {
             return $this->$meta;
        } else {
             return get_post_meta($this->ID, self::COURSE_HOMEURL_META, true);
        }
    }
}

class CourseController
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        // Behavior on the main site
        add_filter("post_type_link",
                   array(get_called_class(), "filter_post_link"), 10, 4);

        // Behavior in the wp-admin area
        static::configure_rendering_in_edit_form();
        add_action("edit_post", array(get_called_class(), "edit_post_cb"), 10, 2);

        static::auto_fields_controller()->hook();
    }

    /**
     * Make it so that labs exist.
     *
     * Under WordPress, almost everything publishable is a post. register_post_type() is
     * invoked to create a particular flavor of posts that describe labs.
     */
    static function register_post_type ()
    {
        $taxonomy_slug = CourseTaxonomy::get_taxonomy_slug();
        register_post_type(
            Course::get_post_type(),
            array(
                'labels'             => array(
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
                    'not_found'          => ___( 'No labs found.' ),
                    'not_found_in_trash' => ___( 'No labs found in Trash.' )
                ),
                'description'        => ___( 'EPFL labs and research groups' ),
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'menu_position'      => 51,
                'query_var'          => true,
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'menu_icon'          => 'dashicons-welcome-write-blog',
                'taxonomies'         => array($taxonomy_slug, 'category', 'post_tag'),
                'supports'           => array( 'custom-fields' ),
                'register_meta_box_cb' => array(get_called_class(), 'add_meta_boxes')
            ));
    }

    /**
     * Called to add meta boxes on an "edit" page in wp-admin.
     */
    static function add_meta_boxes ()
    {
        static::auto_fields_controller()->add_meta_boxes();
    }

    private static function auto_fields_controller ()
    {
        return new AutoFieldsController(Course::class);
    }

    static function filter_post_link ($orig_link, $post, $unused_leavename, $unused_is_sample)
    {
        $course = Course::get($post);
        if (! $course) return $orig_link;
        $true_permalink = $course->get_url();
        return $true_permalink ? $true_permalink : $orig_link;
    }

    static function configure_rendering_in_edit_form ()
    {
        add_action("edit_form_after_title", function ($wp_post) {
                $course = Course::get($wp_post);
                if (! $course) return;
               CourseController::render_readonly_in_edit_form($course);
            });

    }

    function render_readonly_in_edit_form ($course)
    {
        $wp_post = $course->wp_post();
        $permalink = get_permalink($wp_post);
        global $post;
        ?>
    <h1><?php echo $wp_post->post_title; ?></h1>
    <p><b>Permalink:</b> <a href="<?php echo $permalink; ?>"><?php echo $permalink; ?></a></p>
    <?php echo $wp_post->post_content; ?>
        <?php
    }

    static private $saving = array();

    static function edit_post_cb ($post_id, $wp_post)
    {
        $course = Course::get($wp_post);
        if (! $course) { return; }
        // Prevent silly infinite recursion: by calling ->sync(), we
        // write and wind up triggering this callback a second time
        if (! self::$saving[$post_id]) {
            self::$saving[$post_id] = true;
            try {
                $course->sync();
            } finally {
                unset(self::$saving[$post_id]);
            }
        }
    }
}

CourseTaxonomyController::hook();
CourseController::hook();
