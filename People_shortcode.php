<?php

namespace EPFL\WS\People;


use WP_Error;

class PeopleShortCode {

  /*
   * Init
   */
  function __construct() {
    add_shortcode('people', array($this, 'wp_shortcode'));
    add_action("admin_print_footer_scripts", array($this, 'people_shortcode_button_script'));
    // include function from /inc/epfl-ws.php
    require_once(dirname(__FILE__) . "/inc/epfl-ws.php");
    $this->ws = new \EPFL\WS\epflws();
  }

  /*
   * Main logic
   */
  function wp_shortcode($atts, $content=null, $tag='') {
    // normalize attribute keys, lowercase
    $atts = array_change_key_case((array)$atts, CASE_LOWER);

    // override default attributes with user attributes
    $people_atts = shortcode_atts([ 'tmpl'      => 'default_aZ_pic_side', // https://jahia.epfl.ch/cms/site/jahia6/lang/en/external-content/list-of-people/compose#faq-454977
                                    'lang'      => 'en',   // en, fr
                                    'unit'      => 'sti-it',
                                    'url'       => '',
                                    'subtree'   => '',
                                    'struct'    => '',
                                    'function'  => '',
                                    'responsive'  => '',
                                    'nophone'  => '',
                                    'nooffice'  => '',
                                ], $atts, $tag);

    $tmpl       = esc_attr($people_atts['tmpl']);
    $lang       = esc_attr($people_atts['lang']);
    $unit       = esc_attr($people_atts['unit']);
    $url        = esc_attr($people_atts['url']);
    $subtree    = esc_attr($people_atts['subtree']);
    $struct     = esc_attr($people_atts['struct']);
    $function   = esc_attr($people_atts['function']);
    $responsive = esc_attr($people_atts['responsive']);
    $nophone    = esc_attr($people_atts['nophone']);
    $nooffice   = esc_attr($people_atts['nooffice']);

    // In case the user specify an url, just take it. Otherwise:
    if (!$url) {
      $url = 'https://people.epfl.ch/cgi-bin/getProfiles?unit='.$unit.'&tmpl='.$tmpl.'&lang='.$lang;
      if ($subtree)
        $url .= '&subtree='.$subtree;
      if ($struct)
        $url .= '&struct='.$struct;
      if ($function)
        $url .= '&function=' . $function;
      if ($responsive)
        $url .= '&responsive=' . $responsive;
      if ($nophone)
        $url .= '&nophone=' . $nophone;
      if ($nooffice)
        $url .= '&nooffice=' . $nooffice;
    }

    echo "<!-- epfl-people shortcode / url : " . $url . " -->" ;
    // fetch people's html
    if ( $peopleurl = $this->ws->validate_url( $url, "people.epfl.ch" ) ) {
      $people = $this->ws->get_items( $peopleurl, array('timeout' => 10) );
    } else {
      $error = new WP_Error( 'epfl-ws-people-shortcode', 'URL not validated', 'URL: ' . $url . ' returned an error' );
      $this->ws->log( $error );
    }

    return $people;
  }


  /*
   * Add the People button to TinyMCE
   */
  function people_shortcode_button_script() {
    if(wp_script_is("quicktags")) {
      ?>
        <script type="text/javascript">
          QTags.addButton(
            "people_shortcode",
            "People",
            callback
          );
          var peopleDoc = '<!--\n' +
                        '= People short code Information =\n' +
                        'Note that more detailed information can be found on the plugin page in the administration section of your site or on GitHub.\n\n' +
                        'People Shortcode allows you to integrate EPFL People (trombinoscope) in any Wordpress pages or posts. ' +
                        'To do so, just use the [people unit=sti-it lang=fr ] short code where ever you want to display the news. ' +
                        'In addition, you can be very picky on which news you want, by passing some arguments to the short code.\n' +
                        'Here are some example:\n' +
                        '\t- [people tmpl=default_aZ_pic_side lang=en unit=STI-IT]\n' +
                        '\t- [people tmpl=default_aZ_pic_side lang=en unit=STI subtree=1 struct=1 function=prof nophone=0 nooffice=0 responsive=0]\n' +
                        '\n' +
                        'If you need more specific tuning, you can use [people url=XXX], with composing a valid URL from <https://jahia.epfl.ch/contenu-externe/liste-de-personnes/composer>:\n' +
                        '\t- [people url=https://people.epfl.ch/cgi-bin/getProfiles?lang=en&unit=STI&subtree=1&nophone=1&function=professeur+ordinaire]\n' +
                        '\n' +
                        '\n' +
                        'Finally, the source and documentation of this plugin are part of EPFL-WS, you can find help and participate here: <https://github.com/epfl-sti/wordpress.plugin.ws>\n' +
                        '-->';

          function callback()
          {
            QTags.insertContent(peopleDoc);
          }
        </script>
      <?php
    }
  }

}
//add_shortcode('people', 'EPFL\\WS\\People\\wp_shortcode');
new PeopleShortCode();
?>
