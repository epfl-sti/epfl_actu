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
   */
  function get_items( $url ) {
    $response = wp_remote_get( $url );
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
}
