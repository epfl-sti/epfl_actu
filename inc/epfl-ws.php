<?php
/*
 * File name:   epfl-ws/inc/epfl-ws.php
 * Description: This file regroups common function for EPFL WS
 * Version:     0.1
 * Author:      STI-IT Web
 * Author URI:  mailto:stiitweb@groupes.epfl.ch
 * License:     MIT License / Copyright (c) 2017-2018 EPFL ⋅ STI ⋅ IT
 */

namespace EPFL\WS;

if (! defined( 'ABSPATH' )) {
  die( 'Access denied.' );
}

use WP_Error;

class epflws {

  function __construct() {
  }

  /*
   * A quick var_dump with <pre> tag
   */
  function debug( $var ) {
    print "<pre>";
    var_dump( $var );
    print "</pre>";
  }

  /*
   * Push WP_ERROR to PHP error_log
   *   http://php.net/manual/en/function.error-log.php
   */
  function log( $error ) {
    //if (WP_DEBUG === true) { // probably something you want in prod
      if ( is_array( $error ) || is_object( $error ) ) {
        error_log( print_r( $error, true ) );
      } else {
        error_log( $error );
      }
    //}
  }

  /*
   * Use the WP build in fonction to get remote content
   * Return decoded JSON data if the content-type is 'application/json'
   * @param url  : the fetchable url
   * @param args : array('timeout' => 10), see https://codex.wordpress.org/Function_Reference/wp_remote_get
   * @return the data or log an error
   */
  function get_items( $url, $args=array() ) {
    $response = wp_remote_get( $url, $args );
    //$this->debug($response);
    if ( is_array( $response ) ) {
      $header = $response['headers']; // array of http header lines
      $data = $response['body']; // use the content
      if ( $header["content-type"] === "application/json" ) {
        return json_decode($data);
      } else {
        return $data;
      }
    } else {
      $error = new WP_Error( 'epfl-ws', 'get_items() error', 'Fetching remote content with get_items() and ' . $url . ' returned an error' );
      $this->log( $error );
    }
  }

  /*
   * Basic URL validation
   * At some point it might be useful to get the HTTP status code returned by
   * the response.
   */
  function validate_url( $url, $hostname=null) {
    if (  ( $hostname && parse_url($url, PHP_URL_HOST) === $hostname ) || !$hostname ) {
      return wp_http_validate_url( $url ); // https://developer.wordpress.org/reference/functions/wp_http_validate_url/#
    } else {
      return false;
    }
  }

  /*
   * This allow to insert anchor before the element
   *   i.e. '<a name="' . $ws->get_anchor($item->title) . '"></a>';
   * and also to get the item link in case it's not provided by the API.
   * e.g. https://actu.epfl.ch/news/a-12-million-franc-donation-to-create-a-center-for/
   */
  function get_anchor( $title ) {
    $unwanted_array = array(    'Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
                                'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
                                'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
                                'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                                'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
    $title = strtr( $title, $unwanted_array );
    $title = str_replace(" ", "-", $title);
    $title = str_replace("'", "-", $title);
    $title = strtolower($title);
    $title = substr($title, 0, 50);
    return $title;
  }
}
