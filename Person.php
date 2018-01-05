<?php
/*
 * Plugin Name: EPFL Personal Pages (shortcode)
 * Description: Manage personal pages for EPFL (and external) staff
 * Version:     0.1
 * Author:      STI Web Task Force
 * Author URI:  mailto:stiitweb@groupes.epfl.ch
 */
namespace EPFL\Persons;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

function ___($text)
{
    return __($text, "epfl-person");
}

function __x($text, $context)
{
    return _x($text, $context, "epfl-person");
}

function ends_with($haystack, $needle)
{
    $length = strlen($needle);

    return $length === 0 ||
    (substr($haystack, -$length) === $needle);
}

/**
 * True iff we are on the "/post-new.php" page.
 */
function is_form_new ()
{
    return ends_with(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH),
                     "/post-new.php");
}

class Person
{
    const SLUG = "epfl-person";
}

class PersonConfig
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));

        /* Customize the edit form */
        add_action('edit_form_after_title', array(get_called_class(), 'meta_boxes_above_editor'));
    }

    /**
     * Make it so that people pages exist.
     *
     * Under WordPress, almost everything publishable is a post. register_post_type() is
     * invoked to create a particular flavor of posts that describe people.
     */
    static function register_post_type ()
    {
        register_post_type(
            Person::SLUG,
            array(
                'labels'             => array(
                    'name'               => __x( 'People', 'post type general name' ),
                    'singular_name'      => __x( 'Person', 'post type singular name' ),
                    'menu_name'          => __x( 'EPFL People', 'admin menu' ),
                    'name_admin_bar'     => __x( 'Person', 'add new on admin bar' ),
                    'add_new'            => __x( 'Add New', 'add new person' ),
                    'add_new_item'       => ___( 'Add New Person' ),
                    'new_item'           => ___( 'New Person' ),
                    'edit_item'          => ___( 'Edit Person' ),
                    'view_item'          => ___( 'View Person' ),
                    'all_items'          => ___( 'All People' ),
                    'search_items'       => ___( 'Search People' ),
                    'parent_item_colon'  => ___( 'Parent People:' ),
                    'not_found'          => ___( 'No persons found.' ),
                    'not_found_in_trash' => ___( 'No persons found in Trash.' )
                ),
                'description'        => ___( 'Noteworthy people' ),
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'query_var'          => true,
                'rewrite'            => array( 'slug' => Person::SLUG ),
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'menu_position'      => 26,
                'menu_icon'          => 'dashicons-welcome-learn-more',  // Mortar hat
                'supports'           => array( 'editor', 'thumbnail' ),
                'register_meta_box_cb' => array(get_called_class(), 'add_meta_boxes')
            ));
    }

    /**
     * Add custom fields and behavior to the new person / edit person form
     * using "meta boxes".
     *
     * @see https://code.tutsplus.com/tutorials/how-to-create-custom-wordpress-writemeta-boxes--wp-20336
     */
    static function add_meta_boxes ()
    {
        if (is_form_new()) {
            self::add_meta_box('find_by_sciper', ___('Find person'));
        } else {
            self::add_meta_box('show_person_details', ___('Person details'));
        }
    }

    /**
     * Simpler version of the WordPress add_meta_box function
     *
     * @param $slug Unique name for this meta box. The render function
     * is the method called "render_meta_box_$slug"
     *
     * @param $title The human-readable title for the meta box
     *
     * @param $position The position to render the meta box at;
     *        defaults to "above-editor" (see @link meta_boxes_above_editor).
     *        Can be set to any legal value for the $priority argument
     *        to the WordPress add_meta_box function, in particular "default"
     *        to render the meta box after the editor.
     */
    static function add_meta_box ($slug, $title, $position = null)
    {
        if (! $position) $position = 'above-editor';
        $klass = get_called_class();
        $meta_box_name = self::_get_meta_box_name($slug);
        add_meta_box($meta_box_name, $title,
                     function () use ($meta_box_name, $klass, $slug) {
                         wp_nonce_field($meta_box_name);
                         call_user_func(array($klass, "render_meta_box_$slug"));
                     },
                     null, $position);
    }

    private static function _get_meta_box_name ($slug) {
        return sprintf("%s-meta_box_%s", Person::SLUG, $slug);
    }

    static function render_meta_box_find_by_sciper ()
    {
        ?><input type="text" id="sciper" name="sciper" placeholder="<?php echo ___("SCIPER"); ?>"><?php
    }

    static function render_meta_box_show_person_details ()
    {
        ?><h1>PERSON DETAILS</h1><?php
    }

    /**
     * Render all meta boxes configured to show up above the editor.
     */
    static function meta_boxes_above_editor ($post)
    {
        if ($post->post_type !== Person::SLUG) return;
        do_meta_boxes(get_current_screen(), 'above-editor', $post);
    }
}

PersonConfig::hook();
