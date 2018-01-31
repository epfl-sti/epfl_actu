<?php
/**
 * Activation and deactivation hooks for this plugin
 */


namespace EPFL\WS;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

function _main_plugin_file () {
    return dirname(dirname(__FILE__)) . "/EPFL-ws.php";
}

function plugin_activation_hook($callable) {
    return register_activation_hook(_main_plugin_file(), $callable);
}

function plugin_deactivation_hook($callable) {
    return register_deactivation_hook(_main_plugin_file(), $callable);
}
