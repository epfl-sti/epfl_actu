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

class EPFLWSmain {

	function setup() {
		add_action( 'admin_menu', array( $this, 'ws_menu') );
	}

	function ws_menu() {
		if ( is_admin() ) {
			add_menu_page( 'EPFL Web Services Options', 'EPFL WS', 'manage_options', 'epfl-ws', array( $this, 'ws_home'), 'dashicons-carrot', 80 );
			add_submenu_page( 'epfl-ws', 'Actu', ' • Actu (news)', 'manage_options', 'epfl-actu', array( $this, 'actu_home'));
			add_submenu_page( 'epfl-ws', 'Memento', ' • Memento (events)', 'manage_options', 'epfl-memento', array( $this, 'memento_home'));
			add_submenu_page( 'epfl-ws', 'Infoscience', ' • Infoscience (publications)', 'manage_options', 'epfl-infoscience', array( $this, 'infoscience_home'));
		}
	}

	function ws_home() {
		echo '<div class="wrap">';
		echo '  <h1>EPFL WS 🍅</h1>';
		echo '  <i>This is the summary page of the plugin EPFL WS</i>';
		echo '  <p>This Wordpress plugin aim to unify all EPFL web services in one place.</p>';
		echo '  <p></p>';
		echo '  <p><u>Shortcodes</u>:';
		echo '    <ul style="list-style: square outside;margin-left: 20px;">';
		echo '      <li><a href="'.admin_url( "admin.php?page=epfl-actu" ).'">Actu (news)</a></li>';
		echo '      <li><a href="'.admin_url( "admin.php?page=epfl-infoscience" ).'">Infoscience (publications)</a></li>';
		echo '      <li><a href="'.admin_url( "admin.php?page=epfl-memento" ).'">Memento (events)</a></li>';
		echo '    </ul>';
		echo '  </p>';
		echo '  <p><u>Help, resources and contributing</u>:';
		echo '  <p>Please visit the plugin\'s <a href="https://github.com/epfl-sti/wordpress.plugin.ws">GitHub repository</a> to get latest news, open <a href="https://github.com/epfl-sti/wordpress.plugin.ws/issues">issues</a>, <a href="https://github.com/epfl-sti/wordpress.plugin.ws/pulls">contribute</a> or ask <a href="https://github.com/epfl-sti/wordpress.plugin.ws/issues/new">question</a> to find help.</p>';
		echo '</div>';
	}

	function actu_home() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		echo '<div class="wrap">';
		echo '  <h1>EPFL Actu</h1>';
		echo '  <p>&lt; <a href="'.admin_url( "admin.php?page=epfl-ws" ).'">Back to EPFL WS</a></p>';
		echo '  <h2>Short code</h2>';
		echo '  <p>The <code>[actu]</code> shortcode allows you to integrate EPFL News (actus) in any Wordpress pages or posts. It uses <a href="https://actu.epfl.ch/api-docs/">https://actu.epfl.ch/api-docs/</a> as an application programming interface (API).</p>';
		echo '  <p>This documentation is kept short as it will change as soon as the actu API integrates news features.</p>';
		echo '</div>';
	}

	function infoscience_home() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		echo '<div class="wrap">';
		echo '  <h1>EPFL Infoscience</h1>';
		echo '  <p>&lt; <a href="'.admin_url( "admin.php?page=epfl-ws" ).'">Back to EPFL WS</a></p>';
		echo '  <h2>Short code</h2>';
		echo '  <p>The <code>[infoscience url=https://infoscience.epfl.ch/curator/export/123456]</code> shortcode allows you to integrate EPFL Publication (infoscience) in any Wordpress pages or posts. It uses <a href="https://help-infoscience.epfl.ch/page-59729-en.html">https://infoscience.epfl.ch</a> HTML export as input.</p>';
		echo '  <p>Details on how to find the correct URL to fetch the publications list to integrate with the shortcode can be found <a href="https://help-infoscience.epfl.ch/page-59729-en.html">here</a></p>';
		echo '</div>';
	}

	function memento_home() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		echo '<div class="wrap">';
		echo '  <h1>EPFL Memento</h1>';
		echo '  <p>&lt; <a href="'.admin_url( "admin.php?page=epfl-ws" ).'">Back to EPFL WS</a></p>';
		echo '  <h2>Short code</h2>';
		echo '  <p>The <code>[memento]</code> shortcode allows you to integrate EPFL Events (memento) in any Wordpress pages or posts. It uses <a href="https://memento.epfl.ch/api-docs/">https://memento.epfl.ch/api-docs/ TODO</a> as an application programming interface (API).</p>';
		echo '  <p>This documentation is kept short as it will change as soon as the memento API integrates news features.</p>';
		echo '</div>';
	}

}

// Initialize the plugin.
$epflwsmain = new EPFLWSmain;
$epflwsmain->setup();

// "subplugins"
require_once(dirname(__FILE__) . "/Actu_shortcode.php");
require_once(dirname(__FILE__) . "/Actu.php");
require_once(dirname(__FILE__) . "/Infoscience_shortcode.php");
require_once(dirname(__FILE__) . "/Memento_shortcode.php");


?>
