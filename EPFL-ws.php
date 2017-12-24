<?php
/*
 * Plugin Name: EPFL WS
 * Plugin URI:  https://github.com/epfl-sti/wordpress.plugin.ws
 * Description: Integrate EPFL Web Services on your WordPress site.
 * Version:     0.0.1
 * Author:      STI-IT Web
 * Author URI:  mailto:stiitweb@groupes.epfl.ch
 * License:     MIT License / Copyright (c) 2017 EPFL ⋅ STI ⋅ IT
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
	echo '<p>Here is where the form would go if I actually had options.</p>';
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

?>
