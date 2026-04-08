<?php
namespace Flavor_QA;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        update_option( 'flavor_qa_db_version', FLAVOR_QA_DB_VERSION );

        // Schedule cron events.
        if ( ! wp_next_scheduled( 'flavor_qa_scheduled_scan' ) ) {
            wp_schedule_event( time(), 'qa_weekly', 'flavor_qa_scheduled_scan' );
        }

        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'flavor_qa_scheduled_scan' );
        flush_rewrite_rules();
    }

    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = [];

        // Scans table - tracks scan sessions.
        $sql[] = "CREATE TABLE {$wpdb->prefix}flavor_qa_scans (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_types text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            total_items int(11) NOT NULL DEFAULT 0,
            completed_items int(11) NOT NULL DEFAULT 0,
            issues_found int(11) NOT NULL DEFAULT 0,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset_collate;";

        // Issues table - individual problems found.
        $sql[] = "CREATE TABLE {$wpdb->prefix}flavor_qa_issues (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            scanner_type varchar(50) NOT NULL,
            severity varchar(20) NOT NULL DEFAULT 'warning',
            category varchar(100) NOT NULL,
            title varchar(500) NOT NULL,
            description text NOT NULL,
            location text DEFAULT NULL,
            current_value text DEFAULT NULL,
            suggested_value text DEFAULT NULL,
            auto_fixable tinyint(1) NOT NULL DEFAULT 0,
            is_resolved tinyint(1) NOT NULL DEFAULT 0,
            resolved_at datetime DEFAULT NULL,
            meta_data longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_id (scan_id),
            KEY post_id (post_id),
            KEY scanner_type (scanner_type),
            KEY severity (severity),
            KEY is_resolved (is_resolved)
        ) $charset_collate;";

        // Links table - link inventory.
        $sql[] = "CREATE TABLE {$wpdb->prefix}flavor_qa_links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            url varchar(2083) NOT NULL,
            anchor_text varchar(500) DEFAULT NULL,
            link_type varchar(20) NOT NULL DEFAULT 'internal',
            http_status int(5) DEFAULT NULL,
            redirect_url varchar(2083) DEFAULT NULL,
            response_time float DEFAULT NULL,
            is_broken tinyint(1) NOT NULL DEFAULT 0,
            context text DEFAULT NULL,
            checked_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_id (scan_id),
            KEY post_id (post_id),
            KEY is_broken (is_broken),
            KEY http_status (http_status),
            KEY url (url(191))
        ) $charset_collate;";

        // Screenshots table.
        $sql[] = "CREATE TABLE {$wpdb->prefix}flavor_qa_screenshots (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            viewport varchar(20) NOT NULL,
            viewport_width int(5) NOT NULL,
            viewport_height int(5) DEFAULT NULL,
            file_path varchar(500) NOT NULL,
            file_url varchar(500) NOT NULL,
            file_size bigint(20) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scan_id (scan_id),
            KEY post_id (post_id),
            KEY viewport (viewport)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
    }

    private function set_default_options() {
        $defaults = [
            'flavor_qa_settings' => [
                'scan_frequency'       => 'weekly',
                'link_timeout'         => 30,
                'link_check_external'  => true,
                'seo_min_title_length' => 30,
                'seo_max_title_length' => 60,
                'seo_min_desc_length'  => 120,
                'seo_max_desc_length'  => 160,
                'screenshot_viewports' => [
                    [ 'name' => 'mobile',  'width' => 375,  'height' => 812 ],
                    [ 'name' => 'tablet',  'width' => 768,  'height' => 1024 ],
                    [ 'name' => 'desktop', 'width' => 1440, 'height' => 900 ],
                ],
                'puppeteer_path'       => '',
                'max_concurrent_checks' => 5,
                'ignored_urls'         => [],
                'ignored_post_ids'     => [],
            ],
        ];

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}
