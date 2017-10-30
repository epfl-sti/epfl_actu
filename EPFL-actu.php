<?php
/*
 * Plugin Name: EPFL Actu (shortcode)
 * Description: Insert some EPFL news on your blog (https://news.epfl.ch).
 *              Note that it uses the RSS feed.
 * Version: 0.2
 * Author: Nicolas Borboën
 * Author URI: go.epfl.ch/nbo
 * Usage:
 *   - [actu number=10 tmpl=full]
 *   - [actu number=5 tmpl=short]
 *   - [actu number=3 tmpl=widget]
 */


/*
 * ToDo:
 *    https://wordpress.stackexchange.com/questions/72394/how-to-add-a-shortcode-button-to-the-tinymce-editor
 *   https://actu.epfl.ch/webservice?channel=456&lang=en&template=4&sticker=no
 */


function epfl_actu_wp_shortcode($atts, $content=null, $tag='')
{
  // normalize attribute keys, lowercase
  $atts = array_change_key_case((array)$atts, CASE_LOWER);

  // override default attributes with user attributes
  $actu_atts = shortcode_atts([
                                 'number' => '10',
                                 'tmpl'   => 'full', // full, short, widget
                                 'url'    => 'https://actu.epfl.ch/feeds/rss/STI/en/', // https://help-actu.epfl.ch/flux-rss
                               ], $atts, $tag);

                               $max = esc_attr($actu_atts['number']);
                               $tmpl = esc_attr($actu_atts['tmpl']);
                               $url = esc_attr($actu_atts['url']);

  $curl = curl_init();
  curl_setopt_array($curl, Array(
    CURLOPT_URL            => $url, // 'https://actu.epfl.ch/feeds/rss/mediacom/en/',
    CURLOPT_USERAGENT      => 'jawpb', // just another wp blog
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_ENCODING       => 'UTF-8'
  ));

  $data = curl_exec($curl);
  curl_close($curl);
  $xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
  //die('<pre>' . print_r($xml], TRUE) . '</pre>');
  $tmp = '';
  $cnt = 0;


  if ('full' === $tmpl) {
    foreach ($xml->channel->item as $item) {
      $creator = $item->children('dc', TRUE);
      $tmp .= '<h2>' . $item->title . '</h2>';
      $tmp .= '<p>Created: ' . $item->pubDate . '</p>';
      $tmp .= '<p>Author: ' . $creator . '</p>';
      $tmp .= '<p>' . $item->description . '</p>';
      $tmp .= '<p><a href="' . $item->link . '">Read more: ' . $item->title . '</a></p>';
      $cnt++;
      if ($cnt >= $max) break;
    }
  }

  if ('short' === $tmpl) {
    foreach ($xml->channel->item as $item) {
      $creator = $item->children('dc', TRUE);
      $tmp .= '<h2>' . $item->title . '</h2>';
      $tmp .= '<p>Created: ' . $item->pubDate . '</p>';
      preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $item->description, $image);
      $tmp .= '<p><a href="' . $item->link . '" target="_blank"><img src="' . $image['src'] . '" title="' . $item->title . '" /></a></p>';
      $cnt++;
      if ($cnt >= $max) break;
    }
  }

  if ('widget' === $tmpl) {
    foreach ($xml->channel->item as $item) {
      $creator = $item->children('dc', TRUE);
      $tmp .= '<b>' . $item->title . '</b>';
      preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $item->description, $image);
      $tmp .= '<p><a href="' . $item->link . '" target="_blank"><img src="' . $image['src'] . '" title="' . $item->title . '" /></a></p>';
      $cnt++;
      if ($cnt >= $max) break;
    }
  }
  return $tmp;
}
add_shortcode('actu', 'epfl_actu_wp_shortcode');
// Add `add_filter('actu','do_shortcode');` in theme to enable shortcodes in text widgets
?>
