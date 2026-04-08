<?php
namespace Flavor_QA\Scanners;

use Flavor_QA\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for all scanners.
 */
abstract class Scanner_Base {

    /** @var Database */
    protected $db;

    /** @var array Plugin settings */
    protected $settings;

    /**
     * In-memory HTML cache keyed by post_id.
     * Shared across scanner instances via static property so that
     * link scanner + SEO scanner don't re-fetch the same page.
     */
    private static $html_cache = [];

    public function __construct() {
        $this->db       = new Database();
        $this->settings = get_option( 'flavor_qa_settings', [] );
    }

    /**
     * Scan a single post/page.
     */
    abstract public function scan_post( $scan_id, $post_id );

    /**
     * Get the scanner type identifier.
     */
    abstract public function get_type();

    /**
     * Report an issue found during scanning.
     */
    protected function report_issue( $scan_id, $post_id, $data ) {
        $defaults = [
            'scan_id'      => $scan_id,
            'post_id'      => $post_id,
            'scanner_type' => $this->get_type(),
            'severity'     => 'warning',
            'category'     => '',
            'title'        => '',
            'description'  => '',
            'location'     => '',
            'current_value'   => '',
            'suggested_value' => '',
            'auto_fixable'    => 0,
            'meta_data'       => [],
        ];
        $data = wp_parse_args( $data, $defaults );
        return $this->db->insert_issue( $data );
    }

    /**
     * Get the rendered HTML content of a post, with caching.
     * Multiple scanners processing the same post will share one HTTP fetch.
     */
    protected function get_rendered_html( $post_id ) {
        if ( isset( self::$html_cache[ $post_id ] ) ) {
            return self::$html_cache[ $post_id ];
        }

        $url = get_permalink( $post_id );
        if ( ! $url ) {
            self::$html_cache[ $post_id ] = '';
            return '';
        }

        $response = wp_remote_get( $url, [
            'timeout'   => 30,
            'sslverify' => false,
        ] );

        if ( is_wp_error( $response ) ) {
            self::$html_cache[ $post_id ] = '';
            return '';
        }

        $html = wp_remote_retrieve_body( $response );
        self::$html_cache[ $post_id ] = $html;

        // Keep cache from growing unbounded (keep last 20 pages).
        if ( count( self::$html_cache ) > 20 ) {
            $keys = array_keys( self::$html_cache );
            unset( self::$html_cache[ $keys[0] ] );
        }

        return $html;
    }

    /**
     * Flush the HTML cache for a specific post or entirely.
     */
    public static function flush_html_cache( $post_id = null ) {
        if ( $post_id ) {
            unset( self::$html_cache[ $post_id ] );
        } else {
            self::$html_cache = [];
        }
    }

    /**
     * Get post content including page builder data.
     */
    protected function get_post_content( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }
        return $post->post_content;
    }

    /**
     * Detect which page builder (if any) is used for a post.
     */
    protected function detect_builder( $post_id ) {
        $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor_data ) ) {
            return 'elementor';
        }

        $breakdance_data = get_post_meta( $post_id, '_breakdance_data', true );
        if ( ! empty( $breakdance_data ) ) {
            return 'breakdance';
        }

        return 'default';
    }

    /**
     * Parse HTML and extract elements via a simple DOM approach.
     */
    protected function parse_html( $html ) {
        if ( empty( $html ) ) {
            return null;
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        return $dom;
    }
}
