<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all plugin data from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables.
$tables = [
    $wpdb->prefix . 'flavor_qa_scans',
    $wpdb->prefix . 'flavor_qa_issues',
    $wpdb->prefix . 'flavor_qa_links',
    $wpdb->prefix . 'flavor_qa_screenshots',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove options.
delete_option( 'flavor_qa_settings' );
delete_option( 'flavor_qa_db_version' );

// Remove scheduled events.
wp_clear_scheduled_hook( 'flavor_qa_scheduled_scan' );

// Clean up screenshot files.
$upload_dir = wp_upload_dir();
$qa_dir = $upload_dir['basedir'] . '/flavor-qa-screenshots';
if ( is_dir( $qa_dir ) ) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $qa_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $file ) {
        if ( $file->isDir() ) {
            rmdir( $file->getRealPath() );
        } else {
            unlink( $file->getRealPath() );
        }
    }
    rmdir( $qa_dir );
}
