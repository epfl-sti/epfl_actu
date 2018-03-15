<?php
/**
 * "Person-Card" shortcode.
 *
 * Allows to use a shortcode to display the information of the Person.php plugin.
 *
 * Usage:
 *   - [person-card sciper=169419][/person-card]
 *   - [person-card sciper=162030 function='no']Adjunct to the Director[/person-card] # do not display the function title
 *   - [person-card sciper=162030 icon='111111'][/person-card] # show all the font awesome icons (phone - mail - publication - room - internal people page - external people page)
 *   - [person-card sciper=283344 function=Dean imgurl=https://stisrv13.epfl.ch/img/decanat/portrait/ali.sayed.jpg]
 *       Prof. Sayed is world-renowned for his pioneering research on adaptive filters and adaptive networks, and in particular for the energy conservation and diffusion learning approaches he developed for the analysis and design of adaptive structures. His research interests span several areas including adaptation and learning, network and data sciences, information-processing theories, statistical signal processing, and biologically-inspired designs.
 *     [/person-card]
 *   - If you are using the Bootstrap-4-Shortcode plugin (https://github.com/MWDelaney/bootstrap-4-shortcodes), then
 *     - [card-group]
 *         [person-card sciper=283344]Lorem Ipsum[/person-card]
 *         [person-card sciper=169419]Lorem Ipsum[/person-card]
 *         [person-card sciper=123456]Lorem Ipsum[/person-card]
 *       [/card-group]
 *     - [row]
 *          [person-card sciper=111182]Institute Director[/person-card]
 *          [person-card sciper=162030]Adjunct to the Director[/person-card]
 *          [person-card sciper=229808]Secretary[/person-card]
 *       [/row]
 *     - [row class='justify-content-md-center'] to center the content
 *
 * ToDo:
 *   - Translations
 *   - Use schema.org for micro data
 */

namespace EPFL\WS\Person;

use \WP_Error;
use \WP_Query;

require_once(__DIR__ . "/Person.php");
use \EPFL\WS\Persons\Person;

class PersonCardShortCode {

  /**
   * Init
   */
  function __construct() {
    add_shortcode('person-card', array($this, 'wp_shortcode'));
    require_once(dirname(__FILE__) . "/inc/epfl-ws.inc");
    $this->ws = new \EPFL\WS\epflws();
  }

  /**
   * Main logic
   */
  function wp_shortcode($atts, $content=null, $tag='') {
    // normalize attribute keys, lowercase
    $atts = array_change_key_case((array)$atts, CASE_LOWER);

    // override default attributes with user attributes
    $person_atts = shortcode_atts([ 'tmpl'      => 'default',   // Unused, for compatibility
                                    'lang'      => 'en',        // en, fr
                                    'sciper'    => '',
                                    'function'  => '',
                                    'width'     => '20rem',     // card with, default 20rem
                                    'icon'      => '110110',    // icons: phone - mail - publication - room - internal people page - external people page
                                ], $atts, $tag);

    $lang             = esc_attr($person_atts['lang']);
    $sciper           = esc_attr($person_atts['sciper']);
    $this->function   = esc_attr($person_atts['function']);
    $this->width      = esc_attr($person_atts['width']);
    $this->icon       = esc_attr($person_atts['icon']);

    if (!(in_array($lang, array("en", "fr")))) {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Lang error', 'Lang: ' . $lang . ' returned an error' );
      $this->ws->log( $error );
    }
    if ($sciper == '') {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Sciper error', 'Sciper: ' . $sciper . ' returned an error' );
      $this->ws->log( $error );
    }

    $this->person = Person::find_by_sciper($sciper);
    if (!$this->person) {
      error_log("This person not found: " . $sciper);
      return;
    }
    $this->person_post = $this->person->wp_post();
    return $this->display($content);
  }

    private function display($content)
    {
      $default = "";
      $default .= "<div class=\"card bg-light\" style=\"width:" . $this->width . "\">\n";
      $default .=    $this->person->as_thumbnail();
      $default .= "  <div class=\"card-body\">\n";
      $default .= "    <h5 class=\"card-title\">" .  $this->person->get_short_title_and_full_name() . "</h5>\n";
      $default .= "    <div style=\"border-top:1px solid #5A5A5A !important; padding 0px !important; margin 0px !important;\">\n";
      $default .=        ($this->function == 'no') ? '' : $this->person->get_title_as_text() . "\n";
      $default .= "      <div class=\"person-contact\" style=\"float:right\">\n";
      if ($this->icon[0]) {
        $default .= "        <a href=\"tel:" . $this->person->get_phone() . "\" title=\"" . $this->person->get_title_and_full_name() . "'s phone number\">\n";
        $default .= "          <i class=\"fas fa-phone-square\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($this->icon[1]) {
        $default .= "        <a href=\"mailto:" . $this->person->get_mail() . "\" title=\"" . $this->person->get_title_and_full_name() . "'s email\">\n";
        $default .= "          <i class=\"fas fa-envelope-square\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($this->icon[2]) {
        $default .= "        <a href=\"https://plan.epfl.ch/?q=" . $this->person->get_room() . "\" title=\"" .  $this->person->get_title_and_full_name() . "'s office\">\n";
        $default .= "          <i class=\"far fa-map\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($this->icon[3]) {
        $default .= "        <a href=\"https://infoscience.epfl.ch/search?f=author&action=Search&p=" . $this->person->get_full_name() . "\" title=\"" . $this->person->get_short_title_and_full_name() . "'s publications\">\n";
        $default .= "          <i class=\"fas fa-newspaper\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($this->icon[4]) {
        $default .= "        <a href=\"/epfl-person/" . $this->person->get_sciper() . "\" title=\"" . $this->person->get_short_title_and_full_name() . " personal's page\">\n";
        $default .= "          <i class=\"fas fa-user\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($this->icon[5]) {
        $default .= "        <a href=\"https://people.epfl.ch/" . $this->person->get_sciper() . "\" title=\"EPFL " . $this->person->get_short_title_and_full_name() . " personal's page\">\n";
        $default .= "          <i class=\"fas fa-user-circle\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      $default .= "      </div>\n";
      $default .= "    </div>\n";
      $default .= "  </div>\n";
      $default .= "  <div class=\"card-divider bg-warning\" style=\"border-top:2px solid #5A5A5A !important;border-bottom:4px solid #D0131B !important;\"></div>\n";
      $default .= "  <div class=\"card-footer bg-light\" style=\"border-top:2px solid #5A5A5A !important;\">\n";
      $default .=      $content;
      $default .= "  </div>\n";
      $default .= "</div>\n";

      return $default;
    }

} # End class PersonCardShortCode

new PersonCardShortCode();
?>
