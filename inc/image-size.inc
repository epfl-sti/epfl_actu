<?php
/**
 * PHP's getimagesize() done right.
 */

namespace EPFL\WS;

if (! defined( 'ABSPATH' )) {
  die( 'Access denied.' );
}

function _image_size_debug ($msg) {
    // error_log($msg);
}

/**
 * Get the first $nbytes out of $url
 *
 * Use tricks from https://stackoverflow.com/a/4635991/435004 and
 * https://stackoverflow.com/a/17642638
 */
function get_first_n_bytes($url, $nbytes)
{
    $headers = array(
        "Range: bytes=0-$nbytes"
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_BUFFERSIZE, $nbytes);
    curl_setopt($curl, CURLOPT_NOPROGRESS, false);
    curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function(
        $DownloadSize, $Downloaded, $UploadSize, $Uploaded
    ){
        // If $Downloaded exceeds $nbytes + change, return non-0
        // to break the connection
        return ($Downloaded > (2 * $nbytes)) ? 1 : 0;
    });
    $data = curl_exec($curl);
    curl_close($curl);
    return $data;
}

function get_image_size ($url)
{
    $raw = get_first_n_bytes($url, 32768);
    if (! $raw) {
        _image_size_debug("No image at $url");
        return;
    }
    $im = imagecreatefromstring($raw);
    return array("width" => imagesx($im), "height" => imagesy($im));
}
