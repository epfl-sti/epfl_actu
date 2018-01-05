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

class Person {
    const SLUG = "epfl-person";
}

class PersonConfig
{
    static function hook ()
    {
        add_action('init', array(get_called_class(), 'register_post_type'));
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
                'supports'           => array( 'editor', 'thumbnail' )
            ));
    }
}

PersonConfig::hook();
