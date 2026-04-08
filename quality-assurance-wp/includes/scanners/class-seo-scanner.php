<?php
namespace Flavor_QA\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO Scanner.
 * Audits pages for SEO best practices.
 */
class SEO_Scanner extends Scanner_Base {

    public function get_type() {
        return 'seo';
    }

    public function scan_post( $scan_id, $post_id ) {
        $html = $this->get_rendered_html( $post_id );
        if ( empty( $html ) ) {
            return;
        }

        $dom = $this->parse_html( $html );
        if ( ! $dom ) {
            return;
        }

        $xpath = new \DOMXPath( $dom );
        $url   = get_permalink( $post_id );

        // Run all SEO checks.
        $this->check_meta_title( $scan_id, $post_id, $dom, $xpath );
        $this->check_meta_description( $scan_id, $post_id, $dom, $xpath );
        $this->check_heading_hierarchy( $scan_id, $post_id, $dom, $xpath );
        $this->check_image_alt_tags( $scan_id, $post_id, $dom, $xpath );
        $this->check_open_graph( $scan_id, $post_id, $dom, $xpath );
        $this->check_canonical( $scan_id, $post_id, $dom, $xpath, $url );
        $this->check_schema_markup( $scan_id, $post_id, $html );
        $this->check_meta_robots( $scan_id, $post_id, $dom, $xpath );
        $this->check_content_length( $scan_id, $post_id, $dom, $xpath );
        $this->check_internal_links( $scan_id, $post_id, $dom );
        $this->check_keyword_in_url( $scan_id, $post_id, $url );
        $this->check_https_mixed_content( $scan_id, $post_id, $html );
    }

    private function check_meta_title( $scan_id, $post_id, $dom, $xpath ) {
        $title_tags = $dom->getElementsByTagName( 'title' );
        $min = $this->settings['seo_min_title_length'] ?? 30;
        $max = $this->settings['seo_max_title_length'] ?? 60;

        if ( $title_tags->length === 0 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'critical',
                'category'       => 'missing_title',
                'title'          => 'Missing <title> tag',
                'description'    => 'The page has no <title> tag. This is critical for SEO and browser tab display.',
                'auto_fixable'   => 1,
                'suggested_value' => get_the_title( $post_id ),
            ] );
            return;
        }

        $title = trim( $title_tags->item( 0 )->textContent );
        $len = mb_strlen( $title );

        if ( $len < $min ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'warning',
                'category'       => 'short_title',
                'title'          => 'Meta title too short',
                'description'    => sprintf( 'Title is %d characters. Recommended minimum is %d.', $len, $min ),
                'current_value'  => $title,
                'suggested_value' => sprintf( 'Expand to at least %d characters', $min ),
            ] );
        }

        if ( $len > $max ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'warning',
                'category'       => 'long_title',
                'title'          => 'Meta title too long',
                'description'    => sprintf( 'Title is %d characters. Google truncates after ~%d characters.', $len, $max ),
                'current_value'  => $title,
                'suggested_value' => sprintf( 'Shorten to under %d characters', $max ),
            ] );
        }

        if ( $title_tags->length > 1 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'error',
                'category'    => 'duplicate_title',
                'title'       => 'Multiple <title> tags',
                'description' => sprintf( 'Found %d <title> tags. A page should have exactly one.', $title_tags->length ),
            ] );
        }
    }

    private function check_meta_description( $scan_id, $post_id, $dom, $xpath ) {
        $min = $this->settings['seo_min_desc_length'] ?? 120;
        $max = $this->settings['seo_max_desc_length'] ?? 160;

        $metas = $xpath->query( '//meta[@name="description"]' );

        if ( $metas->length === 0 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'     => 'error',
                'category'     => 'missing_meta_description',
                'title'        => 'Missing meta description',
                'description'  => 'No meta description found. This is important for search result snippets.',
                'auto_fixable' => 1,
            ] );
            return;
        }

        $description = $metas->item( 0 )->getAttribute( 'content' );
        $len = mb_strlen( $description );

        if ( empty( $description ) ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'error',
                'category'    => 'empty_meta_description',
                'title'       => 'Empty meta description',
                'description' => 'Meta description tag exists but has no content.',
            ] );
        } elseif ( $len < $min ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'warning',
                'category'       => 'short_meta_description',
                'title'          => 'Meta description too short',
                'description'    => sprintf( 'Description is %d characters. Recommended minimum is %d.', $len, $min ),
                'current_value'  => $description,
            ] );
        } elseif ( $len > $max ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'warning',
                'category'       => 'long_meta_description',
                'title'          => 'Meta description too long',
                'description'    => sprintf( 'Description is %d characters. Google truncates after ~%d characters.', $len, $max ),
                'current_value'  => $description,
            ] );
        }
    }

    private function check_heading_hierarchy( $scan_id, $post_id, $dom, $xpath ) {
        $headings = [];
        for ( $i = 1; $i <= 6; $i++ ) {
            $tags = $dom->getElementsByTagName( 'h' . $i );
            $headings[ $i ] = [];
            foreach ( $tags as $tag ) {
                $headings[ $i ][] = trim( $tag->textContent );
            }
        }

        // Check for missing H1.
        if ( empty( $headings[1] ) ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'error',
                'category'    => 'missing_h1',
                'title'       => 'Missing H1 tag',
                'description' => 'No H1 heading found. Every page should have exactly one H1.',
            ] );
        }

        // Check for multiple H1s.
        if ( count( $headings[1] ) > 1 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'warning',
                'category'    => 'multiple_h1',
                'title'       => 'Multiple H1 tags',
                'description' => sprintf(
                    'Found %d H1 tags: "%s". Best practice is to have exactly one H1.',
                    count( $headings[1] ),
                    implode( '", "', array_map( function( $h ) { return substr( $h, 0, 50 ); }, $headings[1] ) )
                ),
            ] );
        }

        // Check heading hierarchy (no skipping levels).
        $found_levels = [];
        for ( $i = 1; $i <= 6; $i++ ) {
            if ( ! empty( $headings[ $i ] ) ) {
                $found_levels[] = $i;
            }
        }

        for ( $i = 1; $i < count( $found_levels ); $i++ ) {
            $gap = $found_levels[ $i ] - $found_levels[ $i - 1 ];
            if ( $gap > 1 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'warning',
                    'category'    => 'heading_skip',
                    'title'       => 'Heading hierarchy skips level',
                    'description' => sprintf(
                        'Heading jumps from H%d to H%d (skipping H%d). Maintain sequential heading order for accessibility and SEO.',
                        $found_levels[ $i - 1 ],
                        $found_levels[ $i ],
                        $found_levels[ $i - 1 ] + 1
                    ),
                ] );
            }
        }

        // Check for empty headings.
        foreach ( $headings as $level => $texts ) {
            foreach ( $texts as $text ) {
                if ( empty( $text ) ) {
                    $this->report_issue( $scan_id, $post_id, [
                        'severity'    => 'warning',
                        'category'    => 'empty_heading',
                        'title'       => sprintf( 'Empty H%d heading', $level ),
                        'description' => sprintf( 'An H%d heading tag exists but contains no text.', $level ),
                    ] );
                }
            }
        }
    }

    private function check_image_alt_tags( $scan_id, $post_id, $dom, $xpath ) {
        $images = $dom->getElementsByTagName( 'img' );
        $missing_alt = 0;
        $empty_alt = 0;
        $examples = [];

        foreach ( $images as $img ) {
            $src = $img->getAttribute( 'src' );
            $alt = $img->getAttribute( 'alt' );

            if ( ! $img->hasAttribute( 'alt' ) ) {
                $missing_alt++;
                if ( count( $examples ) < 5 ) {
                    $examples[] = $src;
                }
            } elseif ( empty( trim( $alt ) ) ) {
                // Empty alt is OK for decorative images, but flag it.
                $empty_alt++;
            }
        }

        if ( $missing_alt > 0 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'error',
                'category'    => 'missing_alt',
                'title'       => sprintf( '%d image(s) missing alt attribute', $missing_alt ),
                'description' => sprintf(
                    'Found %d images without alt attributes. This hurts accessibility and SEO. Examples: %s',
                    $missing_alt,
                    implode( ', ', array_map( function( $s ) { return '"' . substr( $s, 0, 80 ) . '"'; }, $examples ) )
                ),
                'auto_fixable' => 1,
                'meta_data'    => [ 'missing_count' => $missing_alt, 'example_srcs' => $examples ],
            ] );
        }

        if ( $empty_alt > 3 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'info',
                'category'    => 'empty_alt',
                'title'       => sprintf( '%d image(s) with empty alt text', $empty_alt ),
                'description' => 'Multiple images have empty alt attributes. Ensure decorative images use alt="" and content images have descriptive alt text.',
            ] );
        }
    }

    private function check_open_graph( $scan_id, $post_id, $dom, $xpath ) {
        $required_og = [ 'og:title', 'og:description', 'og:image', 'og:url', 'og:type' ];
        $found = [];

        $metas = $xpath->query( '//meta[starts-with(@property, "og:")]' );
        foreach ( $metas as $meta ) {
            $found[] = $meta->getAttribute( 'property' );
        }

        $missing = array_diff( $required_og, $found );
        if ( ! empty( $missing ) ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'warning',
                'category'    => 'missing_og_tags',
                'title'       => 'Missing Open Graph tags',
                'description' => sprintf(
                    'Missing required OG tags: %s. These are needed for social media sharing.',
                    implode( ', ', $missing )
                ),
                'meta_data' => [ 'missing_tags' => $missing, 'found_tags' => $found ],
            ] );
        }

        // Check Twitter Card.
        $twitter = $xpath->query( '//meta[@name="twitter:card"]' );
        if ( $twitter->length === 0 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'info',
                'category'    => 'missing_twitter_card',
                'title'       => 'Missing Twitter Card meta',
                'description' => 'No twitter:card meta tag found. Add it for better Twitter/X sharing previews.',
            ] );
        }
    }

    private function check_canonical( $scan_id, $post_id, $dom, $xpath, $url ) {
        $links = $xpath->query( '//link[@rel="canonical"]' );

        if ( $links->length === 0 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'warning',
                'category'       => 'missing_canonical',
                'title'          => 'Missing canonical URL',
                'description'    => 'No canonical link found. This can cause duplicate content issues.',
                'suggested_value' => $url,
                'auto_fixable'   => 1,
            ] );
            return;
        }

        $canonical = $links->item( 0 )->getAttribute( 'href' );

        if ( $links->length > 1 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'error',
                'category'    => 'multiple_canonical',
                'title'       => 'Multiple canonical URLs',
                'description' => sprintf( 'Found %d canonical tags. A page should have exactly one.', $links->length ),
            ] );
        }

        // Check canonical points to self or is at least valid.
        if ( ! filter_var( $canonical, FILTER_VALIDATE_URL ) ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'      => 'error',
                'category'      => 'invalid_canonical',
                'title'         => 'Invalid canonical URL',
                'description'   => sprintf( 'Canonical URL "%s" is not a valid URL.', $canonical ),
                'current_value' => $canonical,
            ] );
        }
    }

    private function check_schema_markup( $scan_id, $post_id, $html ) {
        // Check for JSON-LD schema.
        $has_jsonld = preg_match( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>/i', $html );

        // Check for microdata.
        $has_microdata = preg_match( '/itemscope|itemtype/i', $html );

        if ( ! $has_jsonld && ! $has_microdata ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'info',
                'category'    => 'missing_schema',
                'title'       => 'No structured data found',
                'description' => 'No JSON-LD or microdata schema markup detected. Structured data helps search engines understand your content.',
            ] );
        }
    }

    private function check_meta_robots( $scan_id, $post_id, $dom, $xpath ) {
        $robots = $xpath->query( '//meta[@name="robots"]' );

        if ( $robots->length > 0 ) {
            $content = strtolower( $robots->item( 0 )->getAttribute( 'content' ) );
            if ( strpos( $content, 'noindex' ) !== false ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'      => 'critical',
                    'category'      => 'noindex',
                    'title'         => 'Page set to noindex',
                    'description'   => 'This page has a robots noindex directive. It will not appear in search results.',
                    'current_value' => $content,
                ] );
            }
        }
    }

    private function check_content_length( $scan_id, $post_id, $dom, $xpath ) {
        // Try to get main content area text.
        $body = $dom->getElementsByTagName( 'body' );
        if ( $body->length === 0 ) {
            return;
        }

        $text = trim( $body->item( 0 )->textContent );
        // Remove excess whitespace.
        $text = preg_replace( '/\s+/', ' ', $text );
        $word_count = str_word_count( $text );

        if ( $word_count < 300 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'info',
                'category'    => 'thin_content',
                'title'       => 'Thin content detected',
                'description' => sprintf(
                    'Page has approximately %d words. Pages with fewer than 300 words may rank lower.',
                    $word_count
                ),
                'current_value' => (string) $word_count . ' words',
            ] );
        }
    }

    private function check_internal_links( $scan_id, $post_id, $dom ) {
        $site_url = home_url();
        $anchors = $dom->getElementsByTagName( 'a' );
        $internal_count = 0;

        foreach ( $anchors as $anchor ) {
            $href = $anchor->getAttribute( 'href' );
            if ( strpos( $href, $site_url ) === 0 || ( strpos( $href, '/' ) === 0 && strpos( $href, '//' ) !== 0 ) ) {
                $internal_count++;
            }
        }

        if ( $internal_count === 0 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'warning',
                'category'    => 'no_internal_links',
                'title'       => 'No internal links found',
                'description' => 'This page has no internal links. Internal linking helps SEO and user navigation.',
            ] );
        }
    }

    private function check_keyword_in_url( $scan_id, $post_id, $url ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! $path ) {
            return;
        }

        // Check for underscores in URL (should use hyphens).
        if ( strpos( $path, '_' ) !== false ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'warning',
                'category'       => 'url_underscores',
                'title'          => 'URL contains underscores',
                'description'    => 'URLs should use hyphens (-) instead of underscores (_) for word separation.',
                'current_value'  => $path,
                'suggested_value' => str_replace( '_', '-', $path ),
                'auto_fixable'   => 1,
            ] );
        }

        // Check for uppercase in URL.
        if ( preg_match( '/[A-Z]/', $path ) ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'       => 'info',
                'category'       => 'url_uppercase',
                'title'          => 'URL contains uppercase characters',
                'description'    => 'URLs are case-sensitive. Using lowercase is a best practice.',
                'current_value'  => $path,
                'suggested_value' => strtolower( $path ),
            ] );
        }

        // Check for very long URLs.
        if ( strlen( $url ) > 100 ) {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'info',
                'category'    => 'long_url',
                'title'       => 'URL is quite long',
                'description' => sprintf( 'URL is %d characters. Shorter, descriptive URLs tend to perform better.', strlen( $url ) ),
                'current_value' => $url,
            ] );
        }
    }

    private function check_https_mixed_content( $scan_id, $post_id, $html ) {
        if ( is_ssl() ) {
            $http_resources = preg_match_all( '/(?:src|href)=["\']http:\/\//i', $html, $matches );
            if ( $http_resources > 0 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'error',
                    'category'    => 'mixed_content',
                    'title'       => 'Mixed content detected',
                    'description' => sprintf(
                        'Found %d HTTP resources on an HTTPS page. This triggers browser security warnings.',
                        $http_resources
                    ),
                    'auto_fixable' => 1,
                ] );
            }
        }
    }
}
