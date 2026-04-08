<?php
namespace Flavor_QA;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin orchestrator.
 */
class Plugin {

    private static $instance = null;

    /** @var Admin\Admin */
    private $admin;

    /** @var Rest_API */
    private $rest_api;

    /** @var Background_Process */
    private $background_process;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init() {
        // Initialize database check.
        $this->maybe_upgrade_db();

        // Background processing.
        $this->background_process = new Background_Process();

        // REST API.
        $this->rest_api = new Rest_API( $this );
        add_action( 'rest_api_init', [ $this->rest_api, 'register_routes' ] );

        // Admin.
        if ( is_admin() ) {
            $this->admin = new Admin\Admin( $this );
            $this->admin->init();
        }

        // Register cron schedules.
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
    }

    public function add_cron_schedules( $schedules ) {
        $schedules['qa_weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Once Weekly (QA)', 'quality-assurance-wp' ),
        ];
        $schedules['qa_daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __( 'Once Daily (QA)', 'quality-assurance-wp' ),
        ];
        return $schedules;
    }

    /**
     * Get a scanner instance by type.
     */
    public function get_scanner( $type ) {
        switch ( $type ) {
            case 'links':
                return new Scanners\Link_Scanner();
            case 'seo':
                return new Scanners\SEO_Scanner();
            case 'responsive':
                return new Scanners\Responsive_Scanner();
            case 'screenshots':
                return new Scanners\Screenshot_Scanner();
            default:
                return null;
        }
    }

    /**
     * Get a page builder parser.
     */
    public function get_builder_parser( $builder ) {
        switch ( $builder ) {
            case 'elementor':
                return new Builders\Elementor_Parser();
            case 'breakdance':
                return new Builders\Breakdance_Parser();
            default:
                return null;
        }
    }

    /**
     * Get the background process handler.
     */
    public function get_background_process() {
        return $this->background_process;
    }

    /**
     * Start a full scan.
     */
    /**
     * Start a full scan. Creates the scan record.
     * Actual processing is now driven by the REST API process-batch endpoint.
     */
    public function start_scan( $scan_types = [], $post_ids = [] ) {
        $db = new Database();
        $scan_id = $db->create_scan( $scan_types );

        if ( empty( $post_ids ) ) {
            $post_ids = $this->get_all_scannable_posts();
        }

        Scanners\Link_Scanner::clear_url_cache();
        Scanners\Scanner_Base::flush_html_cache();

        return $scan_id;
    }

    /**
     * Get all public posts/pages that should be scanned.
     */
    public function get_all_scannable_posts() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        unset( $post_types['attachment'] );

        $posts = get_posts( [
            'post_type'      => array_values( $post_types ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        return $posts;
    }

    private function maybe_upgrade_db() {
        $installed_version = get_option( 'flavor_qa_db_version', '0' );
        if ( version_compare( $installed_version, FLAVOR_QA_DB_VERSION, '<' ) ) {
            $activator = new Activator();
            $activator->create_tables();
            update_option( 'flavor_qa_db_version', FLAVOR_QA_DB_VERSION );
        }
    }
}
