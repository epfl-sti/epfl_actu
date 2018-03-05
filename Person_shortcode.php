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
 *
 * ToDo:
 *   - Translations
 *   - Use schema.org for micro data
 *   - Allow user to specifiy the person function (e.g. Chair of the Committee on Academic Promotion)
 *   - Group people below a title (i.e. Associate Deans)
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

    if (!(in_array($this->tmpl, array("default", "dean", "bootstrap")))) {
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
      # echo "<!-- DEBUG: " . $numberOfSciper . " " . $i . " " . $this->split . " -->";
      if ( $i == 0 || $i%$this->split == 0 ) {
        # Can make it work with the class card-deck and mx-auto -> when the row is not complete,
        # the "lonley" cards are not fixed with and it is not the behavior we need.
        # https://stackoverflow.com/questions/23794713/flexbox-two-fixed-width-columns-one-flexible/23794791#23794791
        # https://stackoverflow.com/questions/39031224/how-to-center-cards-in-bootstrap-4
        # echo "<div class=\"card-deck mx-auto\">";
        $entrybody = ($this->tmpl == "dean") ? " entry-body" : "";
        echo "<div class=\"row\">";
      }

      $this->person = Person::find_by_sciper($sciper);
      if (!$this->person) {
        continue;
      }
      $this->person_post = $this->person->wp_post();
      foreach (get_post_meta($this->person_post->ID) as $key => $array) {
        $this->person_meta[$key] = $array[0];
      }
      $sti_decanat_box = ($this->tmpl == "dean") ? " sti_decanat_box" : "";
      $colOffset = '';
      if ($this->center && $numberOfSciper*(12/$this->split) < 12 && $i==0) {
        # yup sorry, there's no way to center correctly even number as 1 or 3 on 12 col.
        $offset = floor((12 - ($numberOfSciper*(12/$this->split)) ) / 2);
        $colOffset = "offset-md-" . $offset;
      }
      echo "<div class=\"col-md-" . 12/$this->split . " ".$colOffset.$sti_decanat_box."\">";
      $this->display();
      echo "</div><!-- end col -->";
      $i++;
      if ( ($i > 1 && $i%$this->split == 0) || $numberOfSciper - $i == 0 ) {
        echo "</div><!-- end card deck -->";
      }
    }
  }

  private function display() {
    if ($this->tmpl == 'default' || $this->tmpl == 'bootstrap') {
      echo "
      <div class=\"card bg-light\">".
        get_the_post_thumbnail($this->person_post, 'post-thumbnail', array( 'class' => 'card-img-top', 'title' => $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() ) ) ."
        <div class=\"card-body\">
          <h5 class=\"card-title\">" .  $this->person->get_title()->as_short_greeting() . " " . $this->person->get_full_name() . "
            <div class=\"person-contact\" style=\"float:right\">
              <a href=\"tel:" . $this->person->get_phone() . "\" title=\"" .  $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() . "'s phone number\"><i class=\"fas fa-phone-square\" style=\"color:#5A5A5A;\"></i></a>
              <a href=\"mailto:" . $this->person->get_mail() . "\" title=\"" .  $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() . "'s email\"><i class=\"fas fa-envelope-square\" style=\"color:#5A5A5A;\"></i></a>
              <a href=\"https://infoscience.epfl.ch/search?f=author&action=Search&p=" . $this->person->get_full_name() . "\" title=\"" .  $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() . "'s publications\"><i class=\"fas fa-newspaper\" style=\"color:#5A5A5A;\"></i></a>
              <a href=\"/epfl-person/" . $this->person->get_sciper() . "\" title=\"" .  $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() . " personal's page\"><i class=\"fas fa-user\" style=\"color:#5A5A5A;\"></i></a>
            </div>
          </h5>
          <div style=\"border-top:1px solid #5A5A5A !important; padding 0px !important; margin 0px !important;\">" .  $this->person->get_title()->localize() . "</div>
        </div>
        <div class=\"card-divider bg-warning\" style=\"border-top:2px solid #5A5A5A !important;border-bottom:4px solid #D0131B !important;\"></div>
        <div class=\"card-footer bg-light\" style=\"border-top:2px solid #5A5A5A !important;\">
          lorem ipsum
        </div>
      </div>";
    }

    if ($this->tmpl == 'dean') {
      ?>
      <div class="sti_decanat_portrait">
        <?php echo get_the_post_thumbnail($this->person_post, 'post-thumbnail', array( 'class' => 'card-img-top', 'title' => $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name() ) ); ?>
      </div>
      <div class="sti_decanat_grey">
        <table>
          <td width=70%>
            <div class=sti_decanat_name><?php echo $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name(); ?><br><strong><?php echo $this->person->get_title()->localize(); ?></strong></div>
          </td>
          <td align=right>
            <div class="sti_decanat_buttons"> <!-- buttons -->
              <table>
                <td><img src=/wp-content/themes/epfl-sti/img/src/left_decanat.png></td>
                <td width="29px"><a title="phone: <?php echo $this->person->get_phone(); ?> " href="tel:<?php echo $this->person->get_phone(); ?>"><img onmouseover="this.src='/wp-content/themes/epfl-sti/img/src/phone_on.png';" onmouseout="this.src='/wp-content/themes/epfl-sti/img/src/phone_off.png';" src=/wp-content/themes/epfl-sti/img/src/phone_off.png></a></td>
                <td><a title="email: <?php echo $this->person->get_mail(); ?>" href="mailto:<?php echo $this->person->get_mail();; ?>"><img onmouseover="this.src='/wp-content/themes/epfl-sti/img/src/mail_on.png';" onmouseout="this.src='/wp-content/themes/epfl-sti/img/src/mail_off.png';" src=/wp-content/themes/epfl-sti/img/src/mail_off.png></a></td>
                <td><a title="office: <?php echo $this->person->get_room(); ?>" href="http://plan.epfl.ch/?room=<?php echo $this->person->get_room(); ?>"><img onmouseover="this.src='/wp-content/themes/epfl-sti/img/src/office_on.png';" onmouseout="this.src='/wp-content/themes/epfl-sti/img/src/office_off.png';" src=/wp-content/themes/epfl-sti/img/src/office_off.png></a></td>
                <td><a title='more about <?php echo $this->person->get_title()->as_greeting() . " " . $this->person->get_full_name(); ?>' href="/epfl-person/<?php echo $this->person->get_sciper(); ?>"><img onmouseover="this.src='/wp-content/themes/epfl-sti/img/src/people_on.png';" onmouseout="this.src='/wp-content/themes/epfl-sti/img/src/people_off.png';" src=/wp-content/themes/epfl-sti/img/src/people_off.png></a></td>
              </table>
            </div> <!-- buttons -->
          </td>
        </table>
      </div>
      <div class="sti_decanat_bar sti_textured_header_top"></div>
      <div class="sti_decanat_desc"><?php echo "lorem ipsum"; ?></div>
      <?php
    }
  }

} # End class PersonShortCode

new PersonShortCode();
?>
