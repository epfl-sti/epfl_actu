<?php
/**
 * Work around bugs in WordPress, Polylang etc. that hamper the functionality
 * of this plugin.
 */

namespace EPFL\WS\Bugware;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

add_action('edit_form_top', '\EPFL\WS\Bugware\add_pll_nonce_for_tags');

/**
 * When editing in wp-admin a post of a custom post type that is *not*
 * translated by Polylang, and using the create-a-tag-as-you-type
 * functionality in the right-hand-side “Tags” meta box, Polylang will
 * check an XSRF nonce that it did not send in the first place.
 * This results in a spurious error page.
 */
function add_pll_nonce_for_tags () {
    wp_nonce_field( 'pll_language', '_pll_nonce' );
}
