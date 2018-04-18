<?php

/**
 * [WPQuery] shortcode
 *
 * Examples:
 *
 * [WPQuery type="epfl-actu,post" categories="in-the-media"]
 *
 * [WPQuery type="post" format="video" tags="INST=GM"]
 *
 * Note that there is nothing epfl-specific to this shortcode;
 * however, creating a new plugin just for it doesn't seem warranted.
 */

namespace EPFL\WS\WPQuery;
use WP_Error;

require_once(__DIR__ . "/inc/base-classes.inc");
use \EPFL\WS\Base\Shortcode;
use \EPFL\WS\Base\ListTemplatedShortcodeView;
# Keep \EPFL\WS\Base\WPQueryShortcodeView fully qualified (not imported)
# to prevent it from name-clashing with our own subclass of the same
# name
use \WP_Query;

class WPQueryShortcode extends Shortcode
{
    static function get_name () { return "WPQuery"; }

    static function get_attr_defaults ()
    {
        return array(
            'type'       => null,
            'format'     => null,
            'tag'        => null,
            'tags'       => null,
            'category'   => null,
            'categories' => null
        );
    }

    function render_button_script ()
    {
        ?>
          <script type="text/javascript">
        QTags.addButton(
            "wpquery_shortcode",
            "WPQuery",
            function() {
                  QTags.insertContent("[WPQuery type=\"epfl-actu,post\" tags=\"INST=IGM\" categories=\"in-the-media\" format=\"video\"]");
            });
          </script>
        <?php
    }

    function render ()
    {
        $view = new WPQueryShortcodeView($this->attrs);
        return $view->as_html($this->get_query());
    }

    private function get_query ()
    {
        if (! $this->q) {
            $query_args = array(
                'post_type' => 'any'
            );

            if ($types = $this->attrs['types']) {
                $query_args['post_type'] = array_map(
                    function($t) {
                        return sanitize_key(strtolower($f));
                    },
                    explode(',', $types));
            }

            $tags = $this->attrs['tags'];
            if (! $tags) $tags = $this->attrs['tag'];
            if ($tags) {
                // Passthrough "+" and "," semantics
                $query_args['tag'] = $tags;
            }

            $categories = $this->attrs['categories'];
            if (! $categories) $categories = $this->attrs['category'];
            if ($categories) {
                // Passthrough "+" and "," semantics
                $query_args['category_name'] = $categories;
            }

            if ($formats = $this->attrs['format']) {
                if (! $query_args['tax_query']) {
                    $query_args['tax_query'] = array(relation => 'AND');
                }
                array_push($query_args['tax_query'], array(
                    'taxonomy' => 'post_format',
                    'field'    => 'slug',
                    'terms'    => array_map(
                        function($f) {
                            return ('post-format-' .
                                    sanitize_key(strtolower($f)));
                        },
                        explode(',', $formats)
                    )
                ));
            }

            $this->q = new WP_Query($query_args);
        }
        return $this->q;
    }
}

class WPQueryShortcodeView extends \EPFL\WS\Base\WPQueryShortcodeView
{
    function get_slug () {
        return "wpquery";
    }
}


WPQueryShortcode::hook();
