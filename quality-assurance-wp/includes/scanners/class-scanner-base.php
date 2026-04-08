<?php
namespace Flavor_QA\Scanners;

use Flavor_QA\Database;
use Flavor_QA\Builders\Elementor_Parser;
use Flavor_QA\Builders\Breakdance_Parser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for all scanners.
 *
 * All data is extracted directly from the database — no HTTP requests,
 * no page rendering, no wp_head(). This is both crash-proof and fast.
 */
abstract class Scanner_Base {

    /** @var Database */
    protected $db;

    /** @var array Plugin settings */
    protected $settings;

    /** In-memory cache for assembled HTML keyed by post_id. */
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
     * Build an HTML document from raw database content (no HTTP, no rendering).
     *
     * Assembles a synthetic HTML page from:
     * - Post title as <title>
     * - SEO plugin meta tags from post_meta
     * - Raw post_content run through wpautop (NOT the_content filter)
     * - For Elementor/Breakdance: extracts text content from their JSON
     *
     * This avoids all rendering crashes while still giving scanners
     * a DOM they can parse for links, headings, images, and meta tags.
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

        // ─── Build <head> from post meta ────────────────────

        $title = $post->post_title;
        $head_parts = [];

        // Meta title from SEO plugins.
        $seo_title = $this->get_seo_meta( $post_id, 'title' );
        if ( $seo_title ) {
            $title = $seo_title;
        }
        $head_parts[] = '<title>' . esc_html( $title ) . '</title>';

        // Meta description.
        $description = $this->get_seo_meta( $post_id, 'description' );
        if ( $description ) {
            $head_parts[] = '<meta name="description" content="' . esc_attr( $description ) . '">';
        }

        // Robots meta.
        $robots = $this->get_seo_meta( $post_id, 'robots' );
        if ( $robots ) {
            $head_parts[] = '<meta name="robots" content="' . esc_attr( $robots ) . '">';
        }

        // Canonical.
        $canonical = $this->get_seo_meta( $post_id, 'canonical' );
        if ( ! $canonical ) {
            $canonical = get_permalink( $post_id );
        }
        if ( $canonical ) {
            $head_parts[] = '<link rel="canonical" href="' . esc_url( $canonical ) . '">';
        }

        // Open Graph.
        $og_fields = [
            'og:title'       => $this->get_seo_meta( $post_id, 'og_title' ) ?: $title,
            'og:description' => $this->get_seo_meta( $post_id, 'og_description' ) ?: $description,
            'og:image'       => $this->get_seo_meta( $post_id, 'og_image' ),
            'og:url'         => get_permalink( $post_id ),
            'og:type'        => 'article',
        ];
        foreach ( $og_fields as $prop => $content ) {
            if ( $content ) {
                $head_parts[] = '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $content ) . '">';
            }
        }

        // Twitter Card.
        $twitter_card = get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true )
            ?: get_post_meta( $post_id, 'rank_math_twitter_title', true );
        if ( $twitter_card ) {
            $head_parts[] = '<meta name="twitter:card" content="summary_large_image">';
        }

        // Schema / JSON-LD — check if stored by SEO plugin.
        $schema = get_post_meta( $post_id, '_yoast_wpseo_schema_page_type', true )
            ?: get_post_meta( $post_id, 'rank_math_schema_Article', true );
        if ( $schema ) {
            $head_parts[] = '<script type="application/ld+json">{"@context":"https://schema.org"}</script>';
        }

        $head = implode( "\n", $head_parts );

        // ─── Build <body> from post content + builder data ──

        $body_parts = [];

        // Raw post content (with basic formatting, no risky filters).
        $raw_content = $post->post_content;
        if ( ! empty( $raw_content ) ) {
            $body_parts[] = wpautop( $raw_content );
        }

        // Elementor: extract rendered text/links from widget data.
        $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor_data ) ) {
            $parser = new Elementor_Parser();
            $data = $parser->get_parsed_data( $post_id );
            if ( $data ) {
                // Extract text content from widgets.
                $texts = $parser->extract_text_content( $data );
                foreach ( $texts as $text ) {
                    $body_parts[] = '<div class="elementor-widget" data-element-id="' . esc_attr( $text['element_id'] ) . '">' . $text['content'] . '</div>';
                }
                // Extract images.
                $images = $parser->extract_images( $data );
                foreach ( $images as $img ) {
                    $body_parts[] = '<img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ) . '">';
                }
                // Extract links.
                $links = $parser->extract_links( $data );
                foreach ( $links as $link ) {
                    $body_parts[] = '<a href="' . esc_url( $link['url'] ) . '">[elementor link]</a>';
                }
            }
        }

        // Breakdance: extract rendered text/links from component data.
        $breakdance_data = get_post_meta( $post_id, '_breakdance_data', true );
        if ( ! empty( $breakdance_data ) ) {
            $parser = new Breakdance_Parser();
            $data = $parser->get_parsed_data( $post_id );
            if ( $data ) {
                $texts = $parser->extract_text_content( $data );
                foreach ( $texts as $text ) {
                    $body_parts[] = '<div class="breakdance-element" data-element-id="' . esc_attr( $text['element_id'] ) . '">' . $text['content'] . '</div>';
                }
                $images = $parser->extract_images( $data );
                foreach ( $images as $img ) {
                    $body_parts[] = '<img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ) . '">';
                }
                $links = $parser->extract_links( $data );
                foreach ( $links as $link ) {
                    $body_parts[] = '<a href="' . esc_url( $link['url'] ) . '">[breakdance link]</a>';
                }
            }
        }

        $body = implode( "\n", $body_parts );

        // ─── Assemble ───────────────────────────────────────

        $html = sprintf(
            '<!DOCTYPE html><html><head>%s</head><body>%s</body></html>',
            $head,
            $body
        );

        self::$html_cache[ $post_id ] = $html;

        if ( count( self::$html_cache ) > 30 ) {
            $keys = array_keys( self::$html_cache );
            unset( self::$html_cache[ $keys[0] ] );
        }

        return $html;
    }

    /**
     * Read SEO meta from common SEO plugin post meta fields.
     * Supports: Yoast SEO, RankMath, All in One SEO, SEOPress, The SEO Framework.
     */
    protected function get_seo_meta( $post_id, $field ) {
        $value = '';

        switch ( $field ) {
            case 'title':
                $value = get_post_meta( $post_id, '_yoast_wpseo_title', true )
                    ?: get_post_meta( $post_id, 'rank_math_title', true )
                    ?: get_post_meta( $post_id, '_aioseo_title', true )
                    ?: get_post_meta( $post_id, '_seopress_titles_title', true )
                    ?: get_post_meta( $post_id, '_genesis_title', true )
                    ?: '';
                break;

            case 'description':
                $value = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true )
                    ?: get_post_meta( $post_id, 'rank_math_description', true )
                    ?: get_post_meta( $post_id, '_aioseo_description', true )
                    ?: get_post_meta( $post_id, '_seopress_titles_desc', true )
                    ?: get_post_meta( $post_id, '_genesis_description', true )
                    ?: '';
                break;

            case 'robots':
                // Yoast: _yoast_wpseo_meta-robots-noindex = 1 means noindex.
                $yoast_noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
                if ( '1' === $yoast_noindex ) {
                    return 'noindex';
                }
                $rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
                if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
                    return 'noindex';
                }
                $seopress = get_post_meta( $post_id, '_seopress_robots_index', true );
                if ( 'yes' === $seopress ) {
                    return 'noindex';
                }
                break;

            case 'canonical':
                $value = get_post_meta( $post_id, '_yoast_wpseo_canonical', true )
                    ?: get_post_meta( $post_id, 'rank_math_canonical_url', true )
                    ?: get_post_meta( $post_id, '_aioseo_canonical_url', true )
                    ?: get_post_meta( $post_id, '_seopress_robots_canonical', true )
                    ?: '';
                break;

            case 'og_title':
                $value = get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true )
                    ?: get_post_meta( $post_id, 'rank_math_facebook_title', true )
                    ?: '';
                break;

            case 'og_description':
                $value = get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true )
                    ?: get_post_meta( $post_id, 'rank_math_facebook_description', true )
                    ?: '';
                break;

            case 'og_image':
                $value = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true )
                    ?: get_post_meta( $post_id, 'rank_math_facebook_image', true )
                    ?: '';
                if ( ! $value ) {
                    $thumb_id = get_post_thumbnail_id( $post_id );
                    if ( $thumb_id ) {
                        $value = wp_get_attachment_url( $thumb_id );
                    }
                }
                break;
        }

        return $value;
    }

    /**
     * Flush the HTML cache.
     */
    public static function flush_html_cache( $post_id = null ) {
        if ( $post_id ) {
            unset( self::$html_cache[ $post_id ] );
        } else {
            self::$html_cache = [];
        }
    }

    /**
     * Get raw post content.
     */
    protected function get_post_content( $post_id ) {
        $post = get_post( $post_id );
        return $post ? $post->post_content : '';
    }

    /**
     * Detect which page builder is used.
     */
    protected function detect_builder( $post_id ) {
        if ( get_post_meta( $post_id, '_elementor_data', true ) ) {
            return 'elementor';
        }
        if ( get_post_meta( $post_id, '_breakdance_data', true ) ) {
            return 'breakdance';
        }
        return 'default';
    }

    /**
     * Parse HTML string into DOMDocument.
     * Compatible with PHP 8.2+ (HTML-ENTITIES encoding was removed).
     */
    protected function parse_html( $html ) {
        if ( empty( $html ) ) {
            return null;
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        // Prepend XML encoding declaration instead of mb_convert_encoding
        // which breaks on PHP 8.2+ (HTML-ENTITIES removed).
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        // Remove the XML declaration node if added.
        foreach ( $dom->childNodes as $node ) {
            if ( XML_PI_NODE === $node->nodeType ) {
                $dom->removeChild( $node );
                break;
            }
        }
        libxml_clear_errors();
        return $dom;
    }
}
