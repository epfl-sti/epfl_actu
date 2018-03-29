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
  function hook() {
    add_shortcode('person-card', function($atts, $content=null, $tag='') {
        $shortcode = new PersonCardShortCode();
        return $shortcode->wp_shortcode($atts, $content, $tag);
    }, 10, 3);
  }

  static function log ($msg)
  {
      if ( is_array( $msg ) || is_object( $msg ) ) {
        error_log( print_r( $msg, true ) );
      } else {
        error_log( $msg );
      }
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

    $lang       = esc_attr($person_atts['lang']);
    $sciper     = esc_attr($person_atts['sciper']);
    $function   = esc_attr($person_atts['function']);
    $width      = esc_attr($person_atts['width']);
    $icon       = esc_attr($person_atts['icon']);

    if (!(in_array($lang, array("en", "fr")))) {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Lang error', 'Lang: ' . $lang . ' returned an error' );
      $this->log($error);
      return;
    }
    if ($sciper == '') {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Sciper error', 'Sciper: ' . $sciper . ' returned an error' );
      $this->log($error);
      return;
    }

    $person = Person::find_by_sciper($sciper);
    if (!$person) {
      error_log("This person not found: " . $sciper);
      return;
    }

      $person_post = $person->wp_post();

      $default = "";
      $default .= "<div class=\"card bg-light\" style=\"width:" . $width . "\">\n";
      $default .=    $person->as_thumbnail();
      $default .= "  <div class=\"card-body\">\n";
      $default .= "    <h5 class=\"card-title\">" .  $person->get_short_title_and_full_name() . "</h5>\n";
      $default .= "    <div style=\"border-top:1px solid #5A5A5A !important; padding 0px !important; margin 0px !important;\">\n";
      $default .=        ($function == 'no') ? ''        :
                         $function           ? $function :
                         $person->get_title_as_text();
      $default .=        "\n";
      $default .= "      <div class=\"person-contact\" style=\"float:right\">\n";
      if ($icon[0]) {
        $default .= "        <a href=\"tel:" . $person->get_phone() . "\" title=\"" . $person->get_title_and_full_name() . "'s phone number\">\n";
        $default .= "          <i class=\"fas fa-phone-square\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($icon[1]) {
        $default .= "        <a href=\"mailto:" . $person->get_mail() . "\" title=\"" . $person->get_title_and_full_name() . "'s email\">\n";
        $default .= "          <i class=\"fas fa-envelope-square\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($icon[2]) {
        $default .= "        <a href=\"https://plan.epfl.ch/?q=" . $person->get_room() . "\" title=\"" .  $person->get_title_and_full_name() . "'s office\">\n";
        $default .= "          <i class=\"far fa-map\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($icon[3]) {
        $default .= "        <a href=\"https://infoscience.epfl.ch/search?f=author&action=Search&p=" . $person->get_full_name() . "\" title=\"" . $person->get_short_title_and_full_name() . "'s publications\">\n";
        $default .= "          <i class=\"fas fa-newspaper\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($icon[4]) {
        $default .= "        <a href=\"/epfl-person/" . $person->get_sciper() . "\" title=\"" . $person->get_short_title_and_full_name() . " personal's page\">\n";
        $default .= "          <i class=\"fas fa-user\" style=\"color:#5A5A5A;\"></i>\n";
        $default .= "        </a>\n";
      }
      if ($icon[5]) {
        $default .= "        <a href=\"https://people.epfl.ch/" . $person->get_sciper() . "\" title=\"EPFL " . $person->get_short_title_and_full_name() . " personal's page\">\n";
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

PersonCardShortCode::hook();
