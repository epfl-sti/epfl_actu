<?php
/**
 * Turn any widget into a shortcode.
 */

namespace EPFL\WS\Widgets;

if (! class_exists('WP_Widget')) {
  die( 'Access denied.' );
}

require_once(__DIR__ . "/../inc/i18n.inc");
use function \EPFL\WS\___;
use function \EPFL\WS\__x;

use \Error;

class WidgetShortcode
{
    static function handle ($attrs, $content = "")
    {
        ob_start(null, 0,
                 PHP_OUTPUT_HANDLER_STDFLAGS |
                 PHP_OUTPUT_HANDLER_CLEANABLE);
        try {
            $widget = static::find_widget($attrs);
            $config = $widget->update($attrs, array());

            $widget->widget(array(), $config);
        } catch (Error $e) {
            error_log($e->getMessage());
            echo "<p>" . $e->getMessage() . "</p>";
        } finally {
            $retval = ob_get_clean();
            return $retval;
        }
    }

    static function find_widget ($attrs)
    {
        if ($attrs["class"]) {
            $class_name = preg_replace('@[/]@', '\\', $attrs["class"]);
            $filter = function($widget) use ($class_name) {
                return get_class($widget) === $class_name;
            };
            $summary = sprintf("class=%s", $class_name);
        }
        if (! $filter) {
            throw new Error(___("No widget specified!"));
        }

        global $wp_widget_factory;
        $widgets = array_values(array_filter(
            $wp_widget_factory->widgets, $filter));
        if (! count($widgets)) {
            throw new Error(sprintf(___("Widget not found — %s"), $summary));
        }
        return $widgets[0];
    }
}

add_action("plugins_loaded", function () {
    if (! shortcode_exists("widget")) {
        add_shortcode("widget", array(WidgetShortcode::class, "handle"));
    }
});
