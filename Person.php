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
        add_action('edit_form_after_title',
                   array(get_called_class(), 'meta_boxes_above_editor'));
        add_action(sprintf('save_post_%s', Person::SLUG),
                   array(get_called_class(), 'save_meta_boxes'), 10, 3);
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
     * Called as the 'register_meta_box_cb' at @link register_post_type time.
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

    static function render_meta_box_find_by_sciper ($unused_post)
    {
        ?><input type="text" id="sciper" name="sciper" placeholder="<?php echo ___("SCIPER"); ?>"><?php
    }

    static function save_meta_box_find_by_sciper ($post_id, $post, $is_update)
    {
        update_post_meta($post_id, 'sciper', intval($_REQUEST['sciper']));
    }

    static function render_meta_box_show_person_details ($post)
    {
        $sciper = get_post_meta($post->ID, 'sciper', true);
        ?><h1>PERSON WITH SCIPER <?php echo $sciper; ?></h1><?php
    }

    /**
     * Simpler version of the WordPress add_meta_box function
     *
     * @param $slug Unique name for this meta box. The render function
     * is the method called "render_meta_box_$slug", and the save function
     * is the method called "save_meta_box_$slug" (for the latter see
     * @link save_meta_boxes)
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
                         wp_nonce_field($meta_box_name, $meta_box_name);
                         global $post;
                         call_user_func(
                             array($klass, "render_meta_box_$slug"),
                             $post);
                     },
                     null, $position);
    }

    private static function _get_meta_box_name ($slug) {
        return sprintf("%s-nonce-meta_box_%s", Person::SLUG, $slug);
    }

    /**
     * Call the save_meta_box_$slug for any and all meta box that is
     * posting information.
     *
     * Any and all nonces present in $_REQUEST, for which a corresponding
     * class method exists, are checked; then the class method is called.
     */
    static function save_meta_boxes ($post_id, $post, $is_update) {
        // Bail if we're doing an auto save
        if (defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE) return;

        foreach ($_REQUEST as $k => $v) {
            $matched = array();
            if (preg_match(sprintf('/%s-nonce-meta_box_([a-zA-Z0-9_]+)$/',
                                   Person::SLUG),
                           $k, $matched)) {
                $save_method_name = "save_meta_box_" . $matched[1];
                if (method_exists(get_called_class(), $save_method_name)) {
                    if (! wp_verify_nonce($v, $k)) {
                        wp_die(___("Nonce check failed"));
                    } elseif (! current_user_can('edit_post')) {
                        wp_die(___("Permission denied: edit person"));
                    } else {
                        call_user_func(
                            array(get_called_class(), $save_method_name),
                            $post_id, $post, $is_update);
                    }
                }
            }
        }  // End foreach
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
