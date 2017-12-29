<?php
/*
 * Plugin Name: EPFL Web Services and shortcodes
 * Plugin URI:  https://github.com/epfl-sti/wordpress.plugin.ws
 * Description: Integrate EPFL Web Services on your WordPress site.
 * Version:     0.5
 * Author:      STI-IT Web
 * Author URI:  mailto:stiitweb@groupes.epfl.ch
 * License:     MIT License / Copyright (c) 2017-2018 EPFL ⋅ STI ⋅ IT
 *
 * Usage:
 *   - [actu]
 *   - [actu tmpl=full channel=10 lang=en limit=3]
 *   - [actu tmpl=short channel=10 lang=en limit=20 category=1 title=EPFL subtitle=EPFL text=EPFL faculties=6 themes=1 publics=6]
 *
 * Note:
 *   - Add `add_filter('actu','do_shortcode');` in theme to enable shortcodes in text widgets
 *   - Doc
 *       + https://wiki.epfl.ch/api-rest-actu-memento/actu
 *       + https://help-actu.epfl.ch/outils-webmasters/exporter-tri-articles
 *       + https://actu.epfl.ch/api-docs/
 *
 * Logs:
 *   - v0.1   First WWIP
 *   - v0.2   More template
 *   - v0.3   Widgets enable
 *   - v0.4   Rewritten to use the Actu REST API
 *   - v0.5   Integration to EPFL-WS, full OOP class, TinyMCE button, new API
 */

if (! defined('ABSPATH')) {
    die('Access denied.');
}

add_action( 'admin_menu', 'ws_menu' );

function ws_menu() {
	add_menu_page( 'EPFL Web Services Options', 'EPFL WS', 'manage_options', 'epfl-ws', 'ws_home', 'dashicons-carrot', 80 );
	add_submenu_page( 'epfl-ws', 'Actu', 'Actu (news)', 'manage_options', 'epfl-actu', 'actu_options');
	add_submenu_page( 'epfl-ws', 'Memento', 'Memento (events)', 'manage_options', 'epfl-memento', 'memento_options');
}

function ws_home() {
	echo '<div class="wrap">';
	echo '<h1>EPFL WS</h1>';
	echo '<p>This is the summary page of the plugin EPFL WS</p>';
	echo '<p>This Wordpress plugin aim to unify all EPFL web services in one place.</p>';
	echo '</div>';
}

function actu_options() {
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	echo '<div class="wrap">';
	echo '  <h1>EPFL Actu</h1>';
	echo '  <h2>Short code</h2>';
	echo '  <p>Actu Shortcode allows you to integrate EPFL News (actus) in any Wordpress pages or posts. It uses <a href="https://actu.epfl.ch/api-docs/">https://actu.epfl.ch/api-docs/</a> as an application programming interface (API).</p>';
	echo '  <p>This documentation is kept short as it will change as soon as the actu API integrates news features.</p>';
	echo '</div>';
}

function memento_options() {
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
	}
	echo '<div class="wrap">';
	echo '<p>Here is where the form would go if I actually had options.</p>';
	echo '</div>';
}

require_once(dirname(__FILE__) . "/Actu_shortcode.php");
require_once(dirname(__FILE__) . "/Actu.php");
?>
