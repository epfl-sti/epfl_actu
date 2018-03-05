<?php
/**
 * "Person-Card" shortcode.
 *
 * Allows to use a shortcode to display the information of the Person.php plugin.
 *
 * Usage:
 *   - [person-card sciper=169419]
 *   - [card-group]
 *       [person-card sciper=283344]Lorem Ipsum[/person-card]
 *       [person-card sciper=169419]Lorem Ipsum[/person-card]
 *       [person-card sciper=123456]Lorem Ipsum[/person-card]
 *     [/card-group]
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
    $person_atts = shortcode_atts([ 'tmpl'      => 'default',
                                    'lang'      => 'en',       // en, fr
                                    'sciper'    => '',
                                ], $atts, $tag);

    $this->tmpl       = esc_attr($person_atts['tmpl']);
    $lang             = esc_attr($person_atts['lang']);
    $sciper           = esc_attr($person_atts['sciper']);

    #echo "<!-- epfl-person shortcode / tmpl : " . $this->tmpl . " / lang : " . $lang . " / sciper : " . $sciper . " / center : " . $this->center . "  -->" ;

    if (!(in_array($this->tmpl, array("default", "dean", "bootstrap")))) {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Template error', 'Template: ' . $this->tmpl . ' returned an error' );
      $this->ws->log( $error );
    }
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

  private function display($content) {
    if ($this->tmpl == 'default') {
      return "
      <div class=\"card bg-light\" style=\"width: 20rem;\">".
        $this->person->as_thumbnail() ."
        <div class=\"card-body\">
          <h5 class=\"card-title\">" .  $this->person->get_short_title_and_full_name() . "
            <div class=\"person-contact\" style=\"float:right\">
              <a href=\"tel:" . $this->person->get_phone() . "\" title=\"" .  $this->person->get_title_and_full_name() . "'s phone number\"><i class=\"fas fa-phone-square\" style=\"color:#5A5A5A;\"></i></a>
              <a href=\"mailto:" . $this->person->get_mail() . "\" title=\"" .  $this->person->get_title_and_full_name() . "'s email\"><i class=\"fas fa-envelope-square\" style=\"color:#5A5A5A;\"></i></a>
              <a href=\"https://infoscience.epfl.ch/search?f=author&action=Search&p=" . $this->person->get_full_name() . "\" title=\"" . $this->person->get_short_title_and_full_name() . "'s publications\"><i class=\"fas fa-newspaper\" style=\"color:#5A5A5A;\"></i></a>
              <a href=\"/epfl-person/" . $this->person->get_sciper() . "\" title=\"" .  $this->person->get_short_title_and_full_name() . " personal's page\"><i class=\"fas fa-user\" style=\"color:#5A5A5A;\"></i></a>
            </div>
          </h5>
          <div style=\"border-top:1px solid #5A5A5A !important; padding 0px !important; margin 0px !important;\">" .  $this->person->get_title_as_text() . "</div>
          </div>
        <div class=\"card-divider bg-warning\" style=\"border-top:2px solid #5A5A5A !important;border-bottom:4px solid #D0131B !important;\"></div>
        <div class=\"card-footer bg-light\" style=\"border-top:2px solid #5A5A5A !important;\">
          $content
        </div>
      </div>";
    }
  }

} # End class PersonCardShortCode

new PersonCardShortCode();
?>
