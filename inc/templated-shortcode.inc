<?php
/**
 * Base classes for shortcodes whose rendering can be overridden in themes
 */
namespace EPFL\WS;

require_once(__DIR__ . "/i18n.inc");
use function \EPFL\WS\__x;

/**
 * A shortcode whose rendering consists of a list of results.
 */
abstract class ListTemplatedShortcodeView
{
    abstract function get_slug ();

    abstract function item_as_html ($item);

    function __construct ($shortcode_attrs)
    {
        $this->shortcode_attrs = $shortcode_attrs;
    }

    function get_no_results_msg ()
    {
        return __x("No results", "ListShortcode");

    }
    function no_results_as_html ()
    {
        $classes = implode(" ", $this->get_outer_div_classes());
        $msg = $this->get_no_results_msg();
        $slug = $this->get_slug();
        return apply_filters(
            "epfl_shortcode_${slug}_list_html_no_results",
            "<div class=\"$classes no-results\">$msg</div>",
            $this->shortcode_attrs);
    }

    public function as_html ($items_list)
    {
        $slug = $this->get_slug();

        if (has_filter("epfl_shortcode_${slug}_list_html")) {
            return apply_filters("epfl_shortcode_${slug}_list_html",
                                 $items_list, $this->shortcode_attrs);
        }
        if (! count($items_list)) {
            return $this->no_results_as_html();
        }

        $classes = implode(" ", $this->get_outer_div_classes());
        $html = apply_filters(
            "epfl_shortcode_${slug}_list_html_start",
            "<div class=\"$classes has-results\">",
            $this->shortcode_attrs);
        foreach ($items_list as $item) {
            $html .= apply_filters(
                "epfl_shortcode_${slug}_list_html_item",
                $this->item_as_html($item), $this->shortcode_attrs);
        }
        $html .= apply_filters("epfl_shortcode_${slug}_list_html_end", "</div>",
                               $this->shortcode_attrs);
        return $html;
    }

    protected function get_outer_div_classes () {
        $slug = $this->get_slug();
        return array("epfl-shortcode-list", "epfl-shortcode-$slug");
    }
}
