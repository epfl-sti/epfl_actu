<?php
/**
 * "Person" shortcode.
 *
 * Allows to use a shortcode to display the information of the Person.php plugin.
 *
 * Usage:
 *   - [person sciper=169419 center=on]
 *   - [person sciper=218367,218367,218367,218367,218367 split=3]
 *   - [person sciper=111182,162030 center=on]
 */

namespace EPFL\WS\Person;

use \WP_Error;
use \WP_Query;

require_once(__DIR__ . "/Person.php");
use \EPFL\WS\Persons\Person;

class PersonShortCode {

  /**
   * Init
   */
  function __construct() {
    add_shortcode('person', array($this, 'wp_shortcode'));
    //add_action("admin_print_footer_scripts", array($this, 'person_shortcode_button_script'));
    // include function from /inc/epfl-ws.inc
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
                                    'split'     => 4,          // after how many card you want to break, have to be 12/split
                                    'center'    => false,
                                ], $atts, $tag);

    $this->tmpl       = esc_attr($person_atts['tmpl']);
    $this->lang       = esc_attr($person_atts['lang']);
    $this->sciper     = esc_attr($person_atts['sciper']);
    $this->split      = esc_attr($person_atts['split']);
    $this->center     = esc_attr($person_atts['center']);

    #echo "<!-- epfl-person shortcode / tmpl : " . $this->tmpl . " / lang : " . $this->lang . " / sciper : " . $this->sciper . " / center : " . $this->center . "  -->" ;

    if (!(in_array($this->tmpl, array("default", "decanat", "bootstrap")))) {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Template error', 'Template: ' . $this->tmpl . ' returned an error' );
      $this->ws->log( $error );
    }
    if (!(in_array($this->lang, array("en", "fr")))) {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Lang error', 'Lang: ' . $this->lang . ' returned an error' );
      $this->ws->log( $error );
    }
    if ($this->sciper == '') {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Sciper error', 'Sciper: ' . $this->sciper . ' returned an error' );
      $this->ws->log( $error );
    }
    if (!(in_array($this->split, array(1,2,3,4,6)))) {
      $error = new WP_Error( 'epfl-ws-person-shortcode', 'Split error', 'Split: ' . $this->split . ' returned an error' );
      $this->ws->log( $error );
    }

    $this->scipers = explode(",", $this->sciper);
    $numberOfSciper = count($this->scipers);
    $i = 0;
    foreach ($this->scipers as $sciper) {
      #echo "<!-- DEBUG: " . $numberOfSciper . " " . $i . " " . $this->split . " -->";
      if ( $i == 0 || $i%$this->split == 0 ) {
        # Can make it work with the class card-deck and mx-auto -> when the row is not complete,
        # the "lonley" cards are not fixed with and it is not the behavior we need.
        # https://stackoverflow.com/questions/23794713/flexbox-two-fixed-width-columns-one-flexible/23794791#23794791
        # https://stackoverflow.com/questions/39031224/how-to-center-cards-in-bootstrap-4
        # echo "<div class=\"card-deck mx-auto\">";
        echo "<div class=\"row\">";
      }

      $this->get_person_data($sciper);

      $colOffset = 0;
      if ($this->center && $numberOfSciper*(12/$this->split) < 12 && $i==0) {
        # yup sorry, there's no way to center correctly even number as 1 or 3 on 12 col.
        $offset = floor((12 - ($numberOfSciper*(12/$this->split)) ) / 2);
        $colOffset = "offset-md-" . $offset;
      }
      echo "<div class=\"col-sm-" . 12/$this->split . " ".$colOffset."\">";
      $this->display();
      echo "</div>";
      $i++;
      if ( ($i > 1 && $i%$this->split == 0) || $numberOfSciper - $i == 0 ) {
        echo "</div><!-- end card deck -->";
      }
    }
  }

  private function get_person_data($sciper) {
    $this->person = Person::find_by_sciper($sciper);
    $this->person_post = $this->person->wp_post();
    foreach (get_post_meta($this->person_post->ID) as $key => $array) {
      $this->person_meta[$key] = $array[0];
    }
  }

  private function display() {
    if ($this->tmpl == 'default' || $this->tmpl == 'bootstrap') {
      echo "
      <div class=\"card\">
        <img class=\"card-img-top\" src=\"" . $this->person_meta["epfl_person_external_thumbnail"] . "\" alt=\"" .  $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() . "\">
        <div class=\"card-body\">
          <p class=\"card-text\">" .  $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() . "</p>
          <div class=\"person-contact\" style=\"float:right\">
            <i class=\"fas fa-phone-square\"></i>
            <i class=\"fas fa-envelope-square\"></i>
            <i class=\"fas fa-newspaper\"></i>
            <i class=\"fas fa-user\"></i>
          </div>
        </div>
      </div>";
    }

    if ($this->tmpl == 'test') {
      echo "
      <card>
        <header>
          <img src=\"" . $this->person_meta["epfl_person_external_thumbnail"] . "\" class=\"card-img-top\" />
        </header>
        <main>
          <div class=\"person-footer\">
            <div class=\"person-fullname\">
              " .  $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() . "
            </div>
            <div class=\"person-contact\">
              <i class=\"fas fa-phone-square\"></i>
              <i class=\"fas fa-envelope-square\"></i>
              <i class=\"fas fa-newspaper\"></i>
              <i class=\"fas fa-user\"></i>
            </div>
          </div>
        </main>
      </card>";
    }
  }

} # End class PersonShortCode

new PersonShortCode();
?>
