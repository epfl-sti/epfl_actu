<?php
/*
 * Plugin Name: EPFL Web Services and shortcodes
 * Plugin URI:  https://github.com/epfl-sti/wordpress.plugin.ws
 * Description: Integrate EPFL Web Services on your WordPress site. Integrates with <a href="https://wordpress.org/plugins/wp-subtitle/">WP subtitle</a> if installed.

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

class EPFLWSmain {

	function setup() {
		add_action( 'admin_menu', array( $this, 'ws_menu') );
	}

	function ws_menu() {
		if ( is_admin() ) {
			add_menu_page( 'EPFL Web Services Options', 'EPFL WS', 'manage_options', 'epfl-ws', array( $this, 'ws_home'), 'dashicons-carrot', 80 );
			add_submenu_page( 'epfl-ws', 'Actu',         ' • Actu (news)',                          'manage_options', 'epfl-actu',         array( $this, 'actu_home'));
			add_submenu_page( 'epfl-ws', 'Memento',      ' • Memento (events)',                     'manage_options', 'epfl-memento',      array( $this, 'memento_home'));
			add_submenu_page( 'epfl-ws', 'Infoscience',  ' • Infoscience (publications)',           'manage_options', 'epfl-infoscience',  array( $this, 'infoscience_home'));
			add_submenu_page( 'epfl-ws', 'Organigramme', ' • Organizational charts (organigramme)', 'manage_options', 'epfl-organigramme', array( $this, 'organigramme_home'));
			add_submenu_page( 'epfl-ws', 'People',       ' • People (trombinoscope)',               'manage_options', 'epfl-people',       array( $this, 'people_home'));
			add_submenu_page( 'epfl-ws', 'IS-Academia',  ' • IS-Academia (course list)',            'manage_options', 'epfl-isacademia',   array( $this, 'isacademia_home'));
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
		echo '      <li><a href="'.admin_url( "admin.php?page=epfl-people" ).'">People (trombinoscope)</a></li>';
		echo '      <li><a href="'.admin_url( "admin.php?page=epfl-organigramme" ).'">Organizational charts (organigramme)</a></li>';
		echo '      <li><a href="'.admin_url( "admin.php?page=epfl-isacademia" ).'">IS-Academia (Automatic course list)</a></li>';
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
		echo '  <h2>Shortcode</h2>';
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
		echo '  <h2>Shortcode</h2>';
		echo '  <p>The <code>[infoscience url=https://infoscience.epfl.ch/curator/export/123456]</code> shortcode allows you to integrate EPFL Publications (infoscience) in any Wordpress pages or posts. It uses <a href="https://help-infoscience.epfl.ch/page-59729-en.html">https://infoscience.epfl.ch</a> HTML export as input.</p>';
		echo '  <p>Details on how to find the correct URL to fetch the publications list to integrate with the shortcode can be found <a href="https://help-infoscience.epfl.ch/page-59729-en.html">here</a>.</p>';
		echo '</div>';
	}

	function memento_home() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		echo '<div class="wrap">';
		echo '  <h1>EPFL Memento</h1>';
		echo '  <p>&lt; <a href="'.admin_url( "admin.php?page=epfl-ws" ).'">Back to EPFL WS</a></p>';
		echo '  <h2>Shortcode</h2>';
		echo '  <p>The <code>[memento]</code> shortcode allows you to integrate EPFL Events (memento) in any Wordpress pages or posts. It uses <a href="https://memento.epfl.ch/api-docs/">https://memento.epfl.ch/api-docs/ TODO</a> as an application programming interface (API).</p>';
		echo '  <p><b>Please be aware</b> that this shortcode still relate on the old <a href="https://memento.epfl.ch/api/jahia/mementos/">API</a> and will switch to the <a href="https://memento.epfl.ch/api/v1/events/">new one</a> whenever it\'s ready.</p>';
		echo '</div>';
	}

	function people_home() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		echo '<div class="wrap">';
		echo '  <h1>EPFL People</h1>';
		echo '  <p>&lt; <a href="'.admin_url( "admin.php?page=epfl-ws" ).'">Back to EPFL WS</a></p>';
		echo '  <h2>Shortcode</h2>';
		echo '  <p>The <code>[people tmpl=default_aZ_pic_side lang=en unit=STI-IT]</code> shortcode allows you to integrate EPFL People in any Wordpress pages or posts. It uses <a href="https://jahia.epfl.ch/external-content/list-of-people">https://people.epfl.ch</a> HTML export as input.</p>';
		echo '  <p>It\'s also possible to use <code>[people url=https://people.epfl.ch/cgi-bin/getProfiles?lang=en&unit=STI&subtree=1&nophone=1&function=professeur+ordinaire]</code>. Details on how to find the correct URL to fetch with the shortcode can be found <a href="https://jahia.epfl.ch/cms/site/jahia6/lang/fr/contenu-externe/liste-de-personnes/composer">here</a>.</p>';
		echo '</div>';
	}

	function organigramme_home() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		echo '<div class="wrap">';
		echo '  <h1>EPFL People</h1>';
		echo '  <p>&lt; <a href="'.admin_url( "admin.php?page=epfl-ws" ).'">Back to EPFL WS</a></p>';
		echo '  <h2>Shortcode</h2>';
		echo '  <p>The <code>[organigramme unit=STI lang=en responsive=1]</code> shortcode allows you to integrate EPFL Organizational charts in any Wordpress pages or posts. It uses <a href="https://jahia.epfl.ch/contenu-externe/organigramme">https://organigramme.epfl.ch</a> HTML export as input.</p>';
		echo '</div>';
	}

	function isacademia_home() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		echo '<div class="wrap">';
		echo '  <h1>EPFL IS-Academia</h1>';
		echo '  <p>&lt; <a href="'.admin_url( "admin.php?page=epfl-ws" ).'">Back to EPFL WS</a></p>';
		echo '  <h2>Shortcode</h2>';
		echo '  <p>The <code>[isacademia unit=STI lang=en]</code> shortcode allows you to integrate EPFL automatic course list in any Wordpress pages or posts. It uses the IS-Academia database through a <a href="http://people.epfl.ch/cgi-bin/getCours?unit=XXXX">webservice</a>.</p>';
		echo '  <p>The <code>[isacademia unit=sgm-ens sem=hiver cursus=ba display=byprof detail=L lang=en]</code> shortcode lists the courses of the Mecanical Engineering section, winter semester, bachelor, sorted by teached and full detail..</p>';
		echo '  <p></p>';
		echo '  <p>It\'s also (sort of for now), possible to include a course table <code>[isacademia url=https://isa.epfl.ch/pe/plan_etude_bama_cyclemaster_el_en.html]</code>. Get you URL from <a href="https://is-academia.epfl.ch/planfiche-html">here</a>.</p>';
		echo '</div>';
	}
}

// Initialize the plugin.
$epflwsmain = new EPFLWSmain;
$epflwsmain->setup();

// "subplugins"
require_once(dirname(__FILE__) . "/Actu.php");
require_once(dirname(__FILE__) . "/Actu_shortcode.php");
require_once(dirname(__FILE__) . "/ISAcademia.php");
require_once(dirname(__FILE__) . "/ISAcademia_shortcode.php");
require_once(dirname(__FILE__) . "/Infoscience_shortcode.php");
require_once(dirname(__FILE__) . "/Lab.php");
require_once(dirname(__FILE__) . "/Labs_shortcode.php");
require_once(dirname(__FILE__) . "/OrganizationalUnit.php");
require_once(dirname(__FILE__) . "/widgets/Map.php");
require_once(dirname(__FILE__) . "/widgets/widget2shortcode.php");
require_once(dirname(__FILE__) . "/Memento.php");
require_once(dirname(__FILE__) . "/Memento_shortcode.php");
require_once(dirname(__FILE__) . "/Organigramme_shortcode.php");
require_once(dirname(__FILE__) . "/People_shortcode.php");
require_once(dirname(__FILE__) . "/Person.php");
require_once(dirname(__FILE__) . "/Person_shortcode.php");
