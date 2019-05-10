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
                                        'url'      => '', // if user wants to use its own URL from https://jahia.epfl.ch/external-content/course-plan
                                        'isaurl'   => '', // for the course plan "plan d'étude" https://isa.epfl.ch/pe/plan_etude_bama_cyclemaster_gm_en.html
                                        'legend'   => false, // to display the legend
                                      ], $atts, $tag);

    $lang     = esc_attr($isacademia_atts['lang']);
    $unit     = esc_attr($isacademia_atts['unit']);
    $scipers  = esc_attr($isacademia_atts['scipers']);
    $sem      = esc_attr($isacademia_atts['sem']);
    $cursus   = esc_attr($isacademia_atts['cursus']);
    $display  = esc_attr($isacademia_atts['display']);
    $detail   = esc_attr($isacademia_atts['detail']);
    $url      = esc_attr($isacademia_atts['url']);
    $isaurl   = esc_attr($isacademia_atts['isaurl']);
    $legend   = esc_attr($isacademia_atts['legend']);

    $isacademiaws = false;

    // construct the correct URL with the param
    if ($url == '') {
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
    }
    // if user set the isaurl, he wants a "plan d'étude"
    if  ($isaurl)  {
      $isacademiaws = true;
    }

    // https://jahia.epfl.ch/contenu-externe/liste-automatique-de-cours
    if (!$isacademiaws && $isacademiaurl = $this->ws->validate_url( $url, "people.epfl.ch" ))
    {
      $isacademia = "<!-- epfl-isacademia src URL: " . $isacademiaurl . " -->\n" ;
      $isacademia .= $this->ws->get_items( $isacademiaurl );
      return "<div class=\"isacademia-transcluded\">" . $isacademia . "</div>";
    }
    // https://jahia.epfl.ch/contenu-externe/plan-de-cours
    elseif ($isacademiaws && $isacademiaurl = $this->ws->validate_url( $isaurl, "isa.epfl.ch" ))
    {
      $isacademia = "<!-- epfl-isacademia src URL: " . $isacademiaurl . " -->\n" ;
      // Well, if someone have the time + envy of cleaning this mess, he's welcome
      // In order to GTD, https://github.com/painty/CSS-Used-ChromeExt was used
      // to export the needed CSS from the courses list of jahia, and inline
      // imported here - some cleanup needed (wp_register_style / wp_enqueue_scripts)
      $isacrapycss = <<<EOT
<style>
/*! CSS Used from: http://static.epfl.ch/latest/styles/sti-built.css */
a{background-color:transparent;-webkit-text-decoration-skip:objects;}
a:active,a:hover{outline-width:0;}
img{border-style:none;}
.clear{clear:both;}
body{padding:0;background:#fff;color:#454545;font:normal 1em/1.2em Arial,Helvetica,Verdana,sans-serif;}
h3{margin-top:1.5em;color:#000;font:bold 1.2em/1.4em Arial,Helvetica,Verdana,sans-serif;}
h4{margin-top:1.5em;color:#000;font:bold 1.1em/1.4em Arial,Helvetica,Verdana,sans-serif;}
a{color:#000;text-decoration:none;}
a:focus{outline:0;}
i{font-style:italic;}
img{height:auto;max-width:100%;max-height:100%;vertical-align:middle;}
@media print{
*{box-shadow:none!important;text-shadow:none!important;}
a,a:visited{text-decoration:underline;}
a[href]:after{content:" (" attr(href) ")";}
img{page-break-inside:avoid;}
img{max-width:100%!important;}
h3{orphans:3;widows:3;}
h3{page-break-after:avoid;}
}
/*! CSS Used from: http://sti.epfl.ch/templates/epfl/css/facultes/sti.css */
.local-color{background-color:#8972d5;}
h3 a:hover{color:#8972d5;}
.local-color-light{background-color:#5d41a2;}
.francais .diet_icon{background-position:-90px -144px;}
.anglais .diet_icon{background-position:-108px -144px;}
.allemand .diet_icon{background-position:-72px -144px;}
.winter .little_icon{background-position:-54px -144px;}
.sun .little_icon{background-position:-18px -144px;}
/*! CSS Used from: http://sti.epfl.ch/templates/epfl/css/legacy.css */
#content .line-up{border-top:1px solid #000;padding-top:1px;}
#content .line-down{border-bottom:1px dotted #000;padding-top:1px;}
#content .first-line{background:url(//www.epfl.ch/img/main-navigation.png) repeat;margin-bottom:4px;margin-top:1px;height:38px;width:100%;}
#content .line{margin-bottom:3px;margin-top:2px;width:100%;}
#content .langue{width:15px;height:38px;display:block;float:left;}
#content .cours-title{width:75px;float:left;}
#content .cours-name{padding-top:3px;width:220px;float:left;}
#content .cours-code{width:75px;float:left;}
#content .cours{width:220px;float:left;font-size:11px;}
#content .section{width:60px;float:left;}
#content .section-name{width:60px;float:left;}
#content .enseignement{width:85px;float:left;}
#content .enseignement-name{float:left;line-height:13px;margin-bottom:2px;width:85px;}
#content .bachlor{width:70px;height:100%;border-left:1px solid #fff;float:left;}
#content .bachlor-color{float:left;}
#content .examen{width:80px;border-left:1px solid #fff;float:left;font-size:11px;}
#content .credit{width:66px;height:100%;border-left:1px solid #fff;float:left;}
.titre{font-family:Georgia,'Times New Roman',Times,serif;font-style:italic;font-size:11px;}
.titre_bachlor{font-family:Georgia,'Times New Roman',Times,serif;font-style:italic;font-size:11px;text-align:center;}
.bold{font-weight:bold;}
.cep{width:20px;text-align:center;float:left;}
.red-color{background-color:#e2001a;color:#fff;width:68px;float:left;padding-left:1px;}
.credit-time{text-align:right;padding-right:10px;margin-top:18px;font-weight:bold;font-size:11px;}
.exam-icon{width:18px;margin-left:3px;min-height:38px;float:left;}
.exam-icon .little_icon{margin-right:2px;}
.bachlor-text{text-align:center;padding-bottom:2px;margin-top:18px;color:#fff;font-size:11px;}
#content .cours-name a{font-size:13px;font-weight:bold;text-decoration:none;color:#000;background:url(//www.epfl.ch/img/underline.gif) repeat-x 0 14px;}
#content .cours-name a:hover{background-image:url(//www.epfl.ch/img/underline-hover.png);}
.diet_icon{background:url(//www.epfl.ch/img/icons-plancours.png) no-repeat scroll 0 0 rgba(0,0,0,0);border:medium none;float:left;height:18px;margin-right:0;width:14px;}
.little_icon{background:url(//www.epfl.ch/img/icons-plancours.png) no-repeat scroll 0 0 rgba(0,0,0,0);border:medium none;float:left;height:18px;margin-right:1px;width:18px;}
.francais .diet_icon{background-position:-90px 0;}
.anglais .diet_icon{background-position:-108px 0;}
.allemand .diet_icon{background-position:-72px 0;}
.franglais .diet_icon{background-position:-166px 0;}
.italien .diet_icon{background-position: -148px 0;}
.winter .little_icon{background-position:-54px 0;}
.sun .little_icon{background-position:-18px 0;}
.printemps .little_icon{background-position:0 0;}
.automne .little_icon{background-position:-36px 0;}
.legende{font-family:Georgia,'Times New Roman',Times,serif;font-style:italic;font-size:12px;font-weight:bold;width:5px;float:left;padding-right:20px;}
.img_legende{float:left;width:17px;height:17px;padding-right:20px;}
</style>
EOT;
      $isacademia .= $isacrapycss;
      $isadata = $this->ws->get_items( $isacademiaurl ); // add , array('timeout' => 10) in case of timeout
      $isacademia .= @iconv("ISO-8859-15//TRANSLIT","UTF-8",$isadata);

      if (!$legend || !$isalegendurl = $this->ws->validate_url( $legend, "isa.epfl.ch" )) {
        return "<div class=\"container isacademia-transcluded\"><div class=\"row\"><div class=\"col-md-12\">" . $isacademia . "</div></div></div>";
      } else {
        $isalegenddata = $this->ws->get_items( $isalegendurl );
        $isalegendrendered = @iconv("ISO-8859-15//TRANSLIT","UTF-8",$isalegenddata);
        return "<div class=\"container isacademia-transcluded\"><div class=\"row\"><div class=\"col-md-8\">" . $isacademia . "</div><div class=\"col-md-4\">" . $isalegendrendered . "</div></div></div>";
      }

    } else {
      $error = new WP_Error( 'epfl-ws-isacademia-shortcode', 'URL not validated', 'URL: ' . $url . ' returned an error' );
      $this->ws->log( $error );
    }

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
