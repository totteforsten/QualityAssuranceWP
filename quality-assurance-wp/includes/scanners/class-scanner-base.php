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
     * Shared across scanner instances via static property.
     */
    private static $html_cache = [];

    public function __construct() {
        $this->db       = new Database();
        $this->settings = get_option( 'flavor_qa_settings', [] );
    }

    abstract public function scan_post( $scan_id, $post_id );
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
     * Get the rendered HTML of a post WITHOUT making an HTTP request.
     *
     * Instead of fetching the page via wp_remote_get (which creates a full
     * loopback HTTP request per post - the #1 speed killer), we render
     * the content internally using WordPress's own functions:
     *
     * - wp_head() output buffering for <head> (meta, OG, canonical, schema)
     * - apply_filters('the_content') for the body (links, headings, images)
     *
     * This is ~50-100x faster than HTTP loopback.
     */
    protected function get_rendered_html( $post_id ) {
        if ( isset( self::$html_cache[ $post_id ] ) ) {
            return self::$html_cache[ $post_id ];
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            self::$html_cache[ $post_id ] = '';
            return '';
        }

        // Set up the global post context so wp_head(), the_content filters,
        // SEO plugins, etc. all think we're on this post's page.
        global $wp_query, $wp_the_query;
        $original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        $original_query    = $wp_query;
        $original_thequery = $wp_the_query;

        // Build a fake query that looks like a singular post request.
        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        $fake_query = new \WP_Query( [
            'p'         => $post_id,
            'post_type' => $post->post_type,
        ] );
        $wp_query     = $fake_query;
        $wp_the_query = $fake_query;

        // Render <head> section (meta tags, OG, canonical, schema, etc.).
        ob_start();
        echo '<title>';
        echo esc_html( wp_get_document_title() );
        echo '</title>' . "\n";
        wp_head();
        $head_html = ob_get_clean();

        // Render body content through the_content filter.
        // This processes shortcodes, Elementor widgets, Breakdance components, etc.
        $body_html = apply_filters( 'the_content', $post->post_content );

        // Restore original global state.
        $GLOBALS['post'] = $original_post;
        if ( $original_post ) {
            setup_postdata( $original_post );
        }
        $wp_query     = $original_query;
        $wp_the_query = $original_thequery;

        // Assemble into a full HTML document.
        $html = sprintf(
            '<!DOCTYPE html><html><head>%s</head><body>%s</body></html>',
            $head_html,
            $body_html
        );

        self::$html_cache[ $post_id ] = $html;

        // Keep cache from growing unbounded.
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
     * Parse HTML and extract elements via DOMDocument.
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
