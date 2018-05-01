<?php  // -*- mode: php; -*-

/**
 * Find (by WordPress tags) all content related to a Person, Lab, or
 * other (theme-specific) object.
 */

namespace EPFL\WS\Related;

use \Error;

require_once(__DIR__ . "/i18n.inc");
use function \EPFL\WS\__x;
use function \EPFL\WS\__n;

class Related
{
    function __construct($tag_name)
    {
        $this->tag_name = $tag_name;
    }

    /**
     * @return A two-level associative array whose first-level keys
     *         are post types (custom or not), second-level keys are
     *         post formats (but see special cases below), and values
     *         are lists of WP_Post instances. The second-level key is
     *         "none" if the post type doesn't have `post-formats` in
     *         its `supports` list, and "standard" for a post that
     *         does support `post-formats`, but for which that
     *         particular instance doesn't have a post format.
     */
    public function posts ()
    {
        if (! property_exists($this, '_posts')) {
            $this->_posts = $this->_do_get_posts();
        }
        return $this->_posts;
    }

    private function _do_get_posts ()
    {
        $q = new \WP_Query();
        $posts = $q->query(array(
            'post_type'        => 'any',
            'posts_per_page'   => -1,
            'tax_query'        => array(array(
                'taxonomy' => 'post_tag',
                'field'    => 'id',
                'terms'    => array_map(function($t) { return $t->term_id; },
                                        $this->get_tags())
            )),
            'suppress_filters' => true
        ));
        foreach ($posts as $post) {
            $type = $post->post_type;
            if (! array_key_exists($type, $retval)) {
                $retval[$type] = array();
            }
            if (post_type_supports($type, 'post-formats')) {
                $format = get_post_format($post);
                if (! $format) { $format = "standard"; }
            } else {
                $format = "none";
            }
            if (! array_key_exists($format, $retval[$type])) {
                $retval[$type][$format] = array();
            }
            array_push($retval[$type][$format], $post->post_title);
        }
        return $retval;
    }

    function get_tags ()
    {
        if (! property_exists($this, '_tags')) {
            $this->_tags = (new \WP_Term_Query())->query(array(
                'taxonomy'   => 'post_tag',
                'hide_empty' => false,
                'name'       => $this->tag_name));
        }
        return $this->_tags;
    }

    // TODO: metadata (i.e., terms) can have tags too

    function get_wp_admin_posts_link ($post_type)
    {
        // Remark: the fact that https://github.com/rfmeier/Filter-Post-Formats/blob/master/filter-post-formats.php
        // exists, seems to imply that it is not easy to link to
        // a wp-admin/edit.php page that filters by post format.
        // We can live without that for the time being.
        $tag_list = urlencode(implode(',', array_map(
            function($t) { return $t->slug; },
            $this->get_tags())));
        if ($post_type === 'post') {
            $url = admin_url(sprintf('/edit.php?tag=%s', $tag_list));
        } else {
            $url = admin_url(sprintf('/edit.php?post_type=%s&tag=%s',
                                     $post_type, $tag_list));
        }
        if (! $post_format) return $url;
    }
}

class RelatedController
{
    static function render_for_meta_box ($related_instance)
    {
        $posts = $related_instance->posts();
        if (! $posts) {
            echo __x("None", "related posts");
            return;
        }
        foreach ($posts as $post_type => $posts_struct) {
            $count = array_reduce(
                array_map(function ($a) { return count($a); },
                          array_values($posts_struct)),
                function($carry, $item) { return $carry + $item; },
                0);
            if ($post_type === 'post') {
                $linktext = sprintf(__n('%d post', '%d posts', $count), $count);
            } else {
                $linktext = sprintf(
                    __n('%d post of type %s', '%d posts of type %s', $count),
                    $count,
                    // TODO: There must be an API that gives out
                    // the type name (as passed to register_post_type).
                    $post_type);
            }

            if ($posts_struct["video"]) {
                $video_count = count($posts_struct["video"]);
                $details = sprintf(__n(' (%d video)', ' (%d videos)', $video_count),
                                   $video_count);
            }
            printf('<a href="%s">%s</a>%s<br/>',
                   $related_instance->get_wp_admin_posts_link($post_type),
                   $linktext, $details);
        }
    }
}
