<?php
/**
 * Server-side code for the auto-category widget
 *
 * That widget can be found in the configuration screen of Actu and Memento taxonomies
 * under /wp-admin
 */

namespace EPFL\WS\AutoCategoryWidget;

require_once(__DIR__ . "/ajax.inc");

class AutoCategoryWidgetController {
    function hook ()
    {
        // https://wordpress.stackexchange.com/a/41224/132235
        add_action("admin_print_scripts-term.php", array(get_called_class(), "render_script"));
    }
    function render_script ()
    {
        printf('<script src="%s"></script>',
               plugin_dir_url( __DIR__ ) . 'assets/auto-category-widget.min.js');
    }
}
