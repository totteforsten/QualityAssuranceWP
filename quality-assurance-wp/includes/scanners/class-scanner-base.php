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

    public function __construct() {
        $this->db       = new Database();
        $this->settings = get_option( 'flavor_qa_settings', [] );
    }

    /**
     * Scan a single post/page.
     *
     * @param int $scan_id The scan session ID.
     * @param int $post_id The post to scan.
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
     * Get the rendered HTML content of a post.
     */
    protected function get_rendered_html( $post_id ) {
        $url = get_permalink( $post_id );
        if ( ! $url ) {
            return '';
        }

        $response = wp_remote_get( $url, [
            'timeout'   => 30,
            'sslverify' => false,
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        return wp_remote_retrieve_body( $response );
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
        // Check Elementor.
        $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor_data ) ) {
            return 'elementor';
        }

        // Check Breakdance.
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
