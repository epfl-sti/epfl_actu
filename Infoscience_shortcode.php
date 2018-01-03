<?php

/**
 * EPFL Infoscience shortcode, by Emmanuel JAEP
 * @see https://github.com/jaepetto/EPFL-SC-Infoscience
 */

namespace EPFL\WS\Infoscience;
use WP_Error;

class InfoscienceShortCode {

    function __construct() {
        add_shortcode('infoscience', array( $this, 'epfl_infoscience_process_shortcode' ));
    }

    function epfl_infoscience_log($message) {
        if (WP_DEBUG === true) {
            if (is_array($message) || is_object($message)) {
                error_log(print_r($message, true));
            } else {
                error_log($message);
            }
        }
    }

    function epfl_infoscience_urlExists($url)
    {

        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);

        $response = curl_exec($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if ($httpCode >= 200 && $httpCode <= 400) {
            return true;
        } else {
            return false;
        }

        curl_close($handle);
    }

    function epfl_infoscience_process_shortcode($attributes, $content = null)
    {
        extract(shortcode_atts(array(
            'url' => ''
        ), $attributes));

        // Check if the result is already in cache
        $result = wp_cache_get($url, 'infoscience');
        if (false === $result){
            if (strcasecmp(parse_url($url, PHP_URL_HOST), 'infoscience.epfl.ch') == 0 && $this->epfl_infoscience_urlExists($url)) {

                // Get the content of the cache
                $page = file_get_contents($url);

                // cache the result
                wp_cache_set($url, $page, 'infoscience');

                // return the page
                return $page;
            } else {
                $error = new WP_Error('not found', 'The url passed is not part of Infoscience or is not found', $url);
                $this->epfl_infoscience_log($error);
            }
        }
    }

}

new InfoscienceShortCode();
