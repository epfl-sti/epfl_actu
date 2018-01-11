<?php

/**
 * A set of abstract base classes for Actu, Memento and more.
 */

namespace EPFL\WS\Base;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

/**
 * Abstract base classes for taxonomies whose terms correspond to an API URL.
 *
 * Instances represent one so-called "term" in one of the EPFL-WS
 * taxonomies such as "epfl-actu-channel" (for the ActuStream
 * subclass) or "epfl-memento-channel" (MementoStream subclass). Each
 * term is also a stream with an API URL from which news, events etc.
 * are continuously fetched.
 */
abstract class StreamedTaxonomy
{
    /**
     * @return The object class for WP posts this StreamedTaxonomy.
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
        require_once (dirname(dirname(__FILE__)) . "/ActuAPI.php");
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
