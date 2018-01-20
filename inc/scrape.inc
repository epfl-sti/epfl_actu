<?php

/**
 * Scrape things out of plain old HTML servers @ EPFL
 */

namespace EPFL\WS;

class HTTPException extends \Exception { }
class DOMException extends \Exception { }

require_once(dirname(__FILE__) . "/i18n.inc");

/**
 * @return DOMDocument
 */
function scrape ($url) {
    $doc = new \DOMDocument();
    if (! @$doc->loadHTML(_http_get($url))) {
        throw new DOMException(sprintf(
            ___("Unable to parse DOM retreived from %s"),
            $url));
    }
    return $doc;
}

/**
 * @return string
 */
function _http_get ($url) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $output = curl_exec($ch);
  if (curl_errno($ch)) {
      curl_close($ch);
      throw new HTTPException(sprintf(
          ___("CURL exception while downloading %s: %s"),
          $url, curl_error($ch)));
  } elseif (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
      curl_close($ch);
      throw new HTTPException(sprintf(
          ___("HTTP Error code %d received while downloading %s"),
          curl_getinfo($ch, CURLINFO_HTTP_CODE),
          $url));
  } else {
      curl_close($ch);
      return $output;
  }
}
