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
 *
 * Subclasses must overload:
 *
 * `get_channel_api_url_slug`
 *
 * `get_post_class`
 *
 */
class StreamedTaxonomy {
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
            $this->url = get_term_meta( $this->ID, $this->get_channel_api_url_slug(), true );
        }
        return $this->url;
    }

    function set_url ($url)
    {
        $this->url = $url;
        delete_term_meta($this->ID, $this->get_channel_api_url_slug());
        add_term_meta($this->ID, $this->get_channel_api_url_slug(), $url);
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
            $actuItem = $post_class::get_or_create($APIelement["news_id"], $APIelement["translation_id"]);
            $actuItem->update($APIelement);
            $actuItem->add_found_in_stream($this);
        }
    }
}