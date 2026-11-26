<?php
/**
 * Uninstall WP WebP Converter.
 *
 * Removes the plugin option and the per-attachment conversion markers.
 * Converted WebP files themselves are left in place — they are your
 * media, not plugin data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wwc_settings' );

global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
     WHERE meta_key IN ('_wwc_webp_file', '_wwc_converted')"
);
// Any pending upload markers (transient safety net for interrupted uploads).
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wwc_pending\_%'"
);
