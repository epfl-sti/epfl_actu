<?php
/*
 * Plugin Name: EPFL Actu (shortcode)
 * Description: Insert some EPFL news on your blog (https://news.epfl.ch).
 *              Note that it uses the RSS feed.
 * Version:     0.3
 * Author:      Nicolas Borboën
 * Author URI:  go.epfl.ch/nbo
 * Usage:
 *   - [actu number=10 tmpl=full]
 *   - [actu number=5 tmpl=short]
 *   - [actu number=3 tmpl=widget]
 * Note:
 *   - Add `add_filter('actu','do_shortcode');` in theme to enable shortcodes in text widgets
 */

/*
 * ToDo:
 *    - Add TinyMCE button: https://wordpress.stackexchange.com/questions/72394/how-to-add-a-shortcode-button-to-the-tinymce-editor
 *    - Check if actu webservice is better than RSS: https://actu.epfl.ch/webservice?channel=456&lang=en&template=4&sticker=no
 *    - Validate RSS's url
 *    - Add Cache (wp_cache)
 *    - Comments
 *    - Add CSS / JS
 */

function epfl_actu_get_rss_content($rss_url)
{
  $curl = curl_init();
  curl_setopt_array($curl, Array(
    CURLOPT_URL            => $rss_url, // 'https://actu.epfl.ch/feeds/rss/mediacom/en/',
    CURLOPT_USERAGENT      => 'jawpb',  // just another wp blog
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_ENCODING       => 'UTF-8'
  ));

  $data = curl_exec($curl);
  curl_close($curl);
  return simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
}

function epfl_actu_display_full($rss_xml, $max_number)
{
  $count=0;
  foreach ($rss_xml->channel->item as $item) {
    $creator = $item->children('dc', TRUE);
    $tmp .= '<h2>' . $item->title . '</h2>';
    $tmp .= '<p>Created: ' . $item->pubDate . '</p>';
    $tmp .= '<p>Author: ' . $creator . '</p>';
    $tmp .= '<p>' . $item->description . '</p>';
    $tmp .= '<p><a href="' . $item->link . '">Read more: ' . $item->title . '</a></p>';
    if ($count++ >= $max_number) break;
  }
  return $tmp;
}

function epfl_actu_display_short($rss_xml, $max_number)
{
  $count=0;
  foreach ($rss_xml->channel->item as $item) {
    $creator = $item->children('dc', TRUE);
    $tmp .= '<h2>' . $item->title . '</h2>';
    $tmp .= '<p>Created: ' . $item->pubDate . '</p>';
    preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $item->description, $image);
    $tmp .= '<p><a href="' . $item->link . '" target="_blank"><img src="' . $image['src'] . '" title="' . $item->title . '" /></a></p>';
    if ($count++ >= $max_number) break;
  }
  return $tmp;
}


/*
<div class="card" style="width: 20rem; margin: 10px">
  <img class="card-img-top" src="https://placehold.it/500x400" alt="Card image cap">
  <div class="card-body">
    <h4 class="card-title">EPFL</h4>
    <p class="card-text">This is the new #<?php echo $i?></p>
    <a href="#" class="btn btn-primary">Read more</a>
  </div>
</div>
*/
function epfl_actu_display_bootstrap_card($rss_xml, $max_number)
{
  $count=0;
  $tmp = "";
  foreach ($rss_xml->channel->item as $item) {
    $creator = $item->children('dc', TRUE);
    $tmp .= "<div class='card' style='width: 20rem; margin: 10px'>";
    preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $item->description, $image);
    $tmp .= '<img class="card-img-top" src="' . $image['src'] . '" title="' . $item->title . '" />';
    $tmp .= "<div class='card-body'>";
    $tmp .= '<h4 class="card-title">' . $item->title . '</h4>';
    $tmp .= '<a href="' . $item->link . '" target="_blank" class="btn btn-primary">Read more</a>';
    $tmp .= '</div>';
    $tmp .= '</div>';
    if ($count++ >= $max_number) break;
  }
  return $tmp;
}

function epfl_actu_display_widget($rss_xml, $max_number)
{
  $count=0;
  foreach ($rss_xml->channel->item as $item) {
    $creator = $item->children('dc', TRUE);
    $tmp .= '<b>' . $item->title . '</b>';
    preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $item->description, $image);
    $tmp .= '<p><a href="' . $item->link . '" target="_blank"><img src="' . $image['src'] . '" title="' . $item->title . '" /></a></p>';
    if ($count++ >= $max_number) break;
  }
  return $tmp;
}

/**
 * Main logic
 **/
function epfl_actu_wp_shortcode($atts, $content=null, $tag='') {
  // normalize attribute keys, lowercase
  $atts = array_change_key_case((array)$atts, CASE_LOWER);

  // override default attributes with user attributes
  $actu_atts = shortcode_atts([  'number' => '10',
                                 'tmpl'   => 'full', // full, short, widget
                                 'url'    => 'https://actu.epfl.ch/feeds/rss/STI/en/', // https://help-actu.epfl.ch/flux-rss
                               ], $atts, $tag);

  $max = esc_attr($actu_atts['number']);
  $tmpl = esc_attr($actu_atts['tmpl']);
  $rss_xml = epfl_actu_get_rss_content(esc_attr($actu_atts['url']));

  switch ($tmpl) {
    default:
    case 'full':
      $display_html = epfl_actu_display_full($rss_xml, $max);
      break;
    case 'short':
      $display_html = epfl_actu_display_short($rss_xml, $max);
      break;
    case 'widget':
      $display_html = epfl_actu_display_widget($rss_xml, $max);
      break;
    case 'bootstrap-card':
      $display_html = epfl_actu_display_bootstrap_card($rss_xml, $max);
      break;
  }
  return $display_html;
}

add_shortcode('actu', 'epfl_actu_wp_shortcode');
?>
