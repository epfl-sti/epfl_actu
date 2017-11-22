<?php

/**
 * "Actu" custom post type.
 *
 * For each entry in actus.epfl.ch that the WordPress administrators
 * are interested in, there is a local copy as a post inside the
 * WordPress database whose contents typically consists of a single
 * shortcode. This allows e.g. putting actus news into the newsletter.
 */

namespace EPFL\Actu;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/i18n.php");

class Actu
{
    const SLUG = "epfl-actu";

    static function hook ()
    {
        $THIS_CLASS = '\EPFL\Actu\Actu';
        add_action('init', array($THIS_CLASS, 'register_post_type'));
        add_filter('enter_title_here', array($THIS_CLASS, 'enter_title_here'),
                   10, 2);
    }

    /**
     * Replace the "Enter title here" prompt for Actu-typed posts
     */
    static function enter_title_here ($text, $post)
    {
        if ($post->post_type != self::SLUG) return $text;
        return __x("Event name", "enter_title_here");
    }

    /**
     * Make it so that actus pages exist.
     *
     * Under WordPress, almost everything publishable is a post.
     * register_post_type() is invoked to create a particular flavor
     * of posts that describe news.
     */
    static function register_post_type ()
    {
        register_post_type(
            self::SLUG,
            array(
                'labels'             => array(
                    'name'               => __x( 'EPFL News', 'post type general name' ),
                    'singular_name'      => __x( 'EPFL News', 'post type singular name' ),
                    'menu_name'          => __x( 'EPFL News', 'admin menu' ),
                    'name_admin_bar'     => __x( 'EPFL News', 'add new on admin bar' ),
                    'view_item'          => ___( 'View EPFL News Item' ),
                    'all_items'          => ___( 'All EPFL News for this site' ),
                    'search_items'       => ___( 'Search News' ),
                    'not_found'          => ___( 'No news found.' ),
                    'not_found_in_trash' => ___( 'No news found in Trash.' )
                ),
                'description'        => ___( 'EPFL News from news.epfl.ch' ),
                'public'             => true,
                'publicly_queryable' => true,
                'show_ui'            => true,
                'show_in_menu'       => true,
                'query_var'          => true,
                'rewrite'            => array( 'slug' => self::SLUG ),
                'capability_type'    => 'post',
                'has_archive'        => true,
                'hierarchical'       => false,
                'menu_position'      => null,
                'menu_icon'          => 'dashicons-megaphone',
                'supports'           => array( 'title', 'editor', 'thumbnail' )
            ));
    }
}
