<?php
namespace Flavor_QA\Admin;

use Flavor_QA\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin pages and menu registration.
 */
class Admin {

    private $plugin;
    private $hook_suffix = [];

    public function __construct( Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    public function init() {
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_menus() {
        // Main menu.
        $this->hook_suffix['dashboard'] = add_menu_page(
            __( 'Quality Assurance', 'quality-assurance-wp' ),
            __( 'QA Tool', 'quality-assurance-wp' ),
            'manage_options',
            'flavor-qa',
            [ $this, 'render_dashboard' ],
            'dashicons-yes-alt',
            80
        );

        // Submenu pages.
        $this->hook_suffix['dead-links'] = add_submenu_page(
            'flavor-qa',
            __( 'Dead Links', 'quality-assurance-wp' ),
            __( 'Dead Links', 'quality-assurance-wp' ),
            'manage_options',
            'flavor-qa-links',
            [ $this, 'render_dead_links' ]
        );

        $this->hook_suffix['seo'] = add_submenu_page(
            'flavor-qa',
            __( 'SEO Report', 'quality-assurance-wp' ),
            __( 'SEO Report', 'quality-assurance-wp' ),
            'manage_options',
            'flavor-qa-seo',
            [ $this, 'render_seo_report' ]
        );

        $this->hook_suffix['responsive'] = add_submenu_page(
            'flavor-qa',
            __( 'Responsive Report', 'quality-assurance-wp' ),
            __( 'Responsive Report', 'quality-assurance-wp' ),
            'manage_options',
            'flavor-qa-responsive',
            [ $this, 'render_responsive_report' ]
        );

        $this->hook_suffix['screenshots'] = add_submenu_page(
            'flavor-qa',
            __( 'Screenshots', 'quality-assurance-wp' ),
            __( 'Screenshots', 'quality-assurance-wp' ),
            'manage_options',
            'flavor-qa-screenshots',
            [ $this, 'render_screenshots' ]
        );

        $this->hook_suffix['bulk-actions'] = add_submenu_page(
            'flavor-qa',
            __( 'Bulk Actions', 'quality-assurance-wp' ),
            __( 'Bulk Actions', 'quality-assurance-wp' ),
            'manage_options',
            'flavor-qa-bulk',
            [ $this, 'render_bulk_actions' ]
        );

        $this->hook_suffix['settings'] = add_submenu_page(
            'flavor-qa',
            __( 'Settings', 'quality-assurance-wp' ),
            __( 'Settings', 'quality-assurance-wp' ),
            'manage_options',
            'flavor-qa-settings',
            [ $this, 'render_settings' ]
        );
    }

    public function enqueue_assets( $hook ) {
        // Only load on our pages.
        if ( ! in_array( $hook, $this->hook_suffix, true ) ) {
            return;
        }

        wp_enqueue_style(
            'flavor-qa-admin',
            FLAVOR_QA_PLUGIN_URL . 'admin/css/admin.css',
            [],
            FLAVOR_QA_VERSION
        );

        wp_enqueue_script(
            'flavor-qa-admin',
            FLAVOR_QA_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery', 'wp-api-fetch' ],
            FLAVOR_QA_VERSION,
            true
        );

        wp_localize_script( 'flavor-qa-admin', 'flavorQA', [
            'apiBase'  => rest_url( 'flavor-qa/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'adminUrl' => admin_url(),
            'pluginUrl' => FLAVOR_QA_PLUGIN_URL,
            'i18n'     => [
                'scanning'    => __( 'Scanning...', 'quality-assurance-wp' ),
                'completed'   => __( 'Scan completed', 'quality-assurance-wp' ),
                'confirm_fix' => __( 'Are you sure you want to apply this fix?', 'quality-assurance-wp' ),
                'confirm_bulk' => __( 'Apply fixes to all selected issues?', 'quality-assurance-wp' ),
                'error'       => __( 'An error occurred. Please try again.', 'quality-assurance-wp' ),
            ],
        ] );
    }

    public function register_settings() {
        register_setting( 'flavor_qa_settings_group', 'flavor_qa_settings', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ) {
        $sanitized = [];

        $sanitized['scan_frequency']       = sanitize_text_field( $input['scan_frequency'] ?? 'weekly' );
        $sanitized['link_timeout']         = absint( $input['link_timeout'] ?? 30 );
        $sanitized['link_check_external']  = ! empty( $input['link_check_external'] );
        $sanitized['seo_min_title_length'] = absint( $input['seo_min_title_length'] ?? 30 );
        $sanitized['seo_max_title_length'] = absint( $input['seo_max_title_length'] ?? 60 );
        $sanitized['seo_min_desc_length']  = absint( $input['seo_min_desc_length'] ?? 120 );
        $sanitized['seo_max_desc_length']  = absint( $input['seo_max_desc_length'] ?? 160 );
        $sanitized['max_concurrent_checks'] = min( 20, absint( $input['max_concurrent_checks'] ?? 5 ) );
        $sanitized['puppeteer_path']       = sanitize_text_field( $input['puppeteer_path'] ?? '' );
        $sanitized['screenshot_api_url']   = esc_url_raw( $input['screenshot_api_url'] ?? '' );
        $sanitized['screenshot_api_key']   = sanitize_text_field( $input['screenshot_api_key'] ?? '' );

        // Screenshot viewports.
        $sanitized['screenshot_viewports'] = [
            [ 'name' => 'mobile',  'width' => 375,  'height' => 812 ],
            [ 'name' => 'tablet',  'width' => 768,  'height' => 1024 ],
            [ 'name' => 'desktop', 'width' => 1440, 'height' => 900 ],
        ];

        if ( ! empty( $input['screenshot_viewports'] ) && is_array( $input['screenshot_viewports'] ) ) {
            $sanitized['screenshot_viewports'] = array_map( function( $vp ) {
                return [
                    'name'   => sanitize_text_field( $vp['name'] ?? 'custom' ),
                    'width'  => absint( $vp['width'] ?? 1440 ),
                    'height' => absint( $vp['height'] ?? 900 ),
                ];
            }, $input['screenshot_viewports'] );
        }

        // Ignored URLs (one per line).
        $sanitized['ignored_urls'] = [];
        if ( ! empty( $input['ignored_urls'] ) ) {
            if ( is_string( $input['ignored_urls'] ) ) {
                $sanitized['ignored_urls'] = array_filter( array_map( 'trim', explode( "\n", $input['ignored_urls'] ) ) );
            } elseif ( is_array( $input['ignored_urls'] ) ) {
                $sanitized['ignored_urls'] = array_filter( array_map( 'trim', $input['ignored_urls'] ) );
            }
        }

        // Ignored post IDs.
        $sanitized['ignored_post_ids'] = [];
        if ( ! empty( $input['ignored_post_ids'] ) ) {
            $sanitized['ignored_post_ids'] = array_map( 'absint', (array) $input['ignored_post_ids'] );
        }

        return $sanitized;
    }

    // ─── Render methods ─────────────────────────────────────

    public function render_dashboard() {
        include FLAVOR_QA_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function render_dead_links() {
        include FLAVOR_QA_PLUGIN_DIR . 'admin/views/dead-links.php';
    }

    public function render_seo_report() {
        include FLAVOR_QA_PLUGIN_DIR . 'admin/views/seo-report.php';
    }

    public function render_responsive_report() {
        include FLAVOR_QA_PLUGIN_DIR . 'admin/views/responsive-report.php';
    }

    public function render_screenshots() {
        include FLAVOR_QA_PLUGIN_DIR . 'admin/views/screenshots.php';
    }

    public function render_bulk_actions() {
        include FLAVOR_QA_PLUGIN_DIR . 'admin/views/bulk-actions.php';
    }

    public function render_settings() {
        include FLAVOR_QA_PLUGIN_DIR . 'admin/views/settings.php';
    }
}
