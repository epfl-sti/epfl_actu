<?php

namespace EPFL\WS\Organigramme;


use WP_Error;

class OrganigrammeShortCode {

  /*
   * Init
   */
  function __construct() {
    add_shortcode('organigramme', array($this, 'wp_shortcode'));
    add_action("admin_print_footer_scripts", array($this, 'organigramme_shortcode_button_script'));
    // include function from /inc/epfl-ws.inc
    require_once(dirname(__FILE__) . "/inc/epfl-ws.inc");
    $this->ws = new \EPFL\WS\epflws();
  }

  /*
   * Main logic
   */
  function wp_shortcode($atts, $content=null, $tag='') {
    // normalize attribute keys, lowercase
    $atts = array_change_key_case((array)$atts, CASE_LOWER);

    // override default attributes with user attributes
    $organigramme_atts = shortcode_atts([ 'lang'       => 'en',   // en, fr
                                          'unit'       => 'STI',  // School in capital letters
                                          'responsive' => '0',
                                        ], $atts, $tag);

    $lang = esc_attr($organigramme_atts['lang']);
    $unit = esc_attr($organigramme_atts['unit']);
    $responsive = esc_attr($organigramme_atts['responsive']);
    if ($responsive) {
      $url  = 'http://organigramme.epfl.ch/organigrammes/exportchart_responsive.do?acronym='.strtoupper($unit).'&lang='.$lang;
    } else {
      $url  = 'http://organigramme.epfl.ch/organigrammes/exportchart_web2010.do?acronym='.strtoupper($unit).'&lang='.$lang;
    }
    echo "<!-- epfl-organigramme shortcode / url : " . $url . " -->" ;
    // fetch organigramme's html
    if ( $organigrammeurl = $this->ws->validate_url( $url, "organigramme.epfl.ch" ) ) {
      $organigramme = "<!-- minified css from https://jahia-prod.epfl.ch/files/content/sites/trombinoscopes/files/organigrammes.css --><style>ul#units_lists,ul#units_lists ul,ul.units{margin-right:0;margin-bottom:0;margin-top:5px;color:black}ul#units_lists li,ul.units li{list-style:none;background:none;margin-right:0;padding:0 2px 0 0;line-height:1.5em}li.category{margin-bottom:30px}ul#units_lists div.title{width:100%;border-bottom:1px solid black;padding-top:5px;font-weight:700;font-size:16px;text-indent:5px;clear:both}ul#units_lists li ul.list li.title{width:650px;font-weight:700;margin-left:5px}ul.units li{font-weight:400;margin-left:5px;clear:both}ul.units li div.name,ul.list li div.name,ul.units li div.accronym,ul.list li div.accronym,ul.units li div.responsible,ul.list li div.responsible{float:left;background:none;padding-right:5px}ul.units li div.name,ul.list li div.name{width:330px}ul.units li div.accronym,ul.list li div.accronym{width:100px}ul.units li div.responsible,ul.list li div.responsible{width:170px}</style>";
      $organigramme .= $this->ws->get_items( $organigrammeurl, array('timeout' => 10) );
      return $organigramme;
    } else {
      $error = new WP_Error( 'epfl-ws-organigramme-shortcode', 'URL not validated', 'URL: ' . $url . ' returned an error' );
      $this->ws->log( $error );
    }
  }


  /*
   * Add the Organigramme button to TinyMCE
   */
  function organigramme_shortcode_button_script() {
    if(wp_script_is("quicktags")) {
      ?>
        <script type="text/javascript">
          QTags.addButton(
            "organigramme_shortcode",
            "Organigramme",
            callback
          );
          var organigrammeDoc = '<!--\n' +
                        '= Organigramme shor code Information =\n' +
                        'Note that more detailed information can be found on the plugin page in the administration section of your site or on GitHub.\n\n' +
                        'Organigramme Shortcode allows you to integrate EPFL Organigramme (Organizational charts) in any Wordpress pages or posts. ' +
                        'To do so, just use the [organigramme unit=STI lang=en] shortcode where ever you want to display the organizational charts. ' +
                        '\n' +
                        '\n' +
                        'Finally, the source and documentation of this plugin are part of EPFL-WS, you can find help and participate here: <https://github.com/epfl-sti/wordpress.plugin.ws>\n' +
                        '-->';

          function callback()
          {
            QTags.insertContent(organigrammeDoc);
          }
        </script>
      <?php
    }
  }

}
//add_shortcode('organigramme', 'EPFL\\WS\\Organigramme\\wp_shortcode');
new OrganigrammeShortCode();
?>
