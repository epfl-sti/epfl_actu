<?php

namespace EPFL\WS\ISAcademia;


use WP_Error;

class ISAcademiaShortCode {

  /*
   * Init
   */
  function __construct() {
    add_shortcode('isacademia', array($this, 'wp_shortcode'));
    add_action("admin_print_footer_scripts", array($this, 'isacademia_shortcode_button_script'));
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
    // https://jahia.epfl.ch/external-content/automatic-course-list#faq-488738
    $isacademia_atts = shortcode_atts([ 'lang'     => 'en',   // en, fr
                                        'unit'     => '',
                                        'scipers'  => '',
                                        'sem'      => '', // semester ete|hiver
                                        'cursus'   => '', // cursus ba|ma|phd
                                        'display'  => '', // sorting, display=byprof
                                        'detail'   => '', // &detail=S > name of course with link + teachers, &detail=M > the same + description and language, &detail=L > the same + curriculum + academic year
                                        'url'      => '', // https://jahia.epfl.ch/external-content/course-plan
                                      ], $atts, $tag);

    $lang     = esc_attr($isacademia_atts['lang']);
    $unit     = esc_attr($isacademia_atts['unit']);
    $scipers  = esc_attr($isacademia_atts['scipers']);
    $sem      = esc_attr($isacademia_atts['sem']);
    $cursus   = esc_attr($isacademia_atts['cursus']);
    $display  = esc_attr($isacademia_atts['display']);
    $detail   = esc_attr($isacademia_atts['detail']);
    $url      = esc_attr($isacademia_atts['url']);

    $isacademiaws = false;

    if (!$url) {
      $url = 'https://people.epfl.ch/cgi-bin/getCours?lang='.$lang;
      if ($unit)
        $url .= '&unit='.$unit;
      if ($scipers)
        $url .= '&scipers='.$scipers;
      if ($sem)
        $url .= '&sem='.$sem;
      if ($cursus)
        $url .= '&cursus='.$cursus;
      if ($display)
        $url .= '&display=' . $display;
      if ($detail)
        $url .= '&detail=' . $detail;
    } else { $isacademiaws = true; }

    echo "<!-- epfl-isacademia shortcode / url : " . $url . " -->" ;
    // fetch isacademia's html
    if ( (!$isacademiaws && $isacademiaurl = $this->ws->validate_url( $url, "people.epfl.ch" )) ||
      ($isacademiaws && $isacademiaurl = $this->ws->validate_url( $url, "isa.epfl.ch" )) ) {
        // DO YOU FEEL MY PAIN ?
      $isacademia = '<style>';
      $isacademia .= $this->ws->get_items( 'https://sti.epfl.ch/templates/epfl/css/legacy.css' );
      $isacademia .= '</style>';
      $isacademia .= $this->ws->get_items( $isacademiaurl ); // add , array('timeout' => 10) in case of timeout
    } else {
      $error = new WP_Error( 'epfl-ws-isacademia-shortcode', 'URL not validated', 'URL: ' . $url . ' returned an error' );
      $this->ws->log( $error );
    }

    return $isacademia;
  }


  /*
   * Add the IS-Academia button to TinyMCE
   */
  function isacademia_shortcode_button_script() {
    if(wp_script_is("quicktags")) {
      ?>
        <script type="text/javascript">
          QTags.addButton(
            "isacademia_shortcode",
            "IS-Academia",
            callback
          );
          var isacademiaDoc = '<!--\n' +
                        '= IS-Academia shortcode Information =\n' +
                        'Note that more detailed information can be found on the plugin page in the administration section of your site or on GitHub.\n\n' +
                        'IS-Academia Shortcode allows you to integrate EPFL automatic course list (IS-Academia) in any Wordpress pages or posts. ' +
                        'To do so, just use the [isacademia unit=sgm-ens lang=en] shortcode where ever you want to display the news. ' +
                        'In addition, you can pass some arguments to the shortcode.\n' +
                        'Here are some example:\n' +
                        '\t- per laboratory: unit=XXX,XXX >the acronym of the laboratory(s)\n' +
                        '\t- per section: unit=XXX-ens\n' +
                        '\t- per teacher: scipers=123456,123457\n' +
                        '\t- per semester: sem=ete or sem=hiver\n' +
                        '\t- per cursus: cursus=ba (bachelor\'s), cursus=ma (master\'s), cursus=phd (phd)\n' +
                        '\t- sorting: display=byprof\n' +
                        '\t- selecting the detail level: \n' +
                        '\t\t + detail=S > name of course with link + teachers \n' +
                        '\t\t + detail=M > the same + description and language \n' +
                        '\t\t + detail=L > the same + curriculum + academic year \n' +
                        '\n' +
                        '\nThis page <https://jahia.epfl.ch/external-content/automatic-course-list#faq-488738> summarize the option.' +
                        '\n' +
                        'Finally, the source and documentation of this plugin are part of EPFL-WS, you can find help and participate here: <https://github.com/epfl-sti/wordpress.plugin.ws>\n' +
                        '-->';

          function callback()
          {
            QTags.insertContent(isacademiaDoc);
          }
        </script>
      <?php
    }
  }

}
//add_shortcode('isacademia', 'EPFL\\WS\\ISAcademia\\wp_shortcode');
new ISAcademiaShortCode();
?>
