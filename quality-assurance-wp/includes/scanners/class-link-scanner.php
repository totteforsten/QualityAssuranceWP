<?php
namespace Flavor_QA\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dead Link Scanner.
 * Crawls post content and checks every link for broken URLs.
 */
class Link_Scanner extends Scanner_Base {

    public function get_type() {
        return 'links';
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

        $site_url = home_url();
        $ignored_urls = isset( $this->settings['ignored_urls'] ) ? $this->settings['ignored_urls'] : [];
        $timeout = isset( $this->settings['link_timeout'] ) ? (int) $this->settings['link_timeout'] : 30;
        $check_external = isset( $this->settings['link_check_external'] ) ? $this->settings['link_check_external'] : true;

        // Extract all links.
        $links = $this->extract_links( $dom, $site_url );

        // Extract image sources.
        $images = $this->extract_images( $dom, $site_url );

        $all_urls = array_merge( $links, $images );

        foreach ( $all_urls as $link_data ) {
            $url = $link_data['url'];

            // Skip anchors, javascript, mailto, tel.
            if ( $this->should_skip_url( $url ) ) {
                continue;
            }

            // Skip ignored URLs.
            if ( $this->is_ignored( $url, $ignored_urls ) ) {
                continue;
            }

            // Determine if internal or external.
            $is_internal = $this->is_internal_url( $url, $site_url );
            $link_type = $is_internal ? 'internal' : 'external';

            // Skip external if configured.
            if ( ! $check_external && ! $is_internal ) {
                continue;
            }

            // Normalize relative URLs.
            $url = $this->normalize_url( $url, $site_url );

            // Check the link.
            $result = $this->check_url( $url, $timeout );

            $is_broken = $this->is_broken_status( $result['status'] );

            // Store the link record.
            $this->db->insert_link( [
                'scan_id'       => $scan_id,
                'post_id'       => $post_id,
                'url'           => $url,
                'anchor_text'   => isset( $link_data['text'] ) ? substr( $link_data['text'], 0, 500 ) : '',
                'link_type'     => $link_type,
                'http_status'   => $result['status'],
                'redirect_url'  => $result['redirect_url'],
                'response_time' => $result['time'],
                'is_broken'     => $is_broken ? 1 : 0,
                'context'       => $link_data['context'],
                'checked_at'    => current_time( 'mysql' ),
            ] );

            // Report issue if broken.
            if ( $is_broken ) {
                $severity = $is_internal ? 'error' : 'warning';
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => $severity,
                    'category'    => 'broken_link',
                    'title'       => sprintf( 'Broken %s link (HTTP %s)', $link_type, $result['status'] ?: 'timeout' ),
                    'description' => sprintf(
                        'The URL "%s" returned HTTP status %s. Found in: %s',
                        $url,
                        $result['status'] ?: 'timeout/error',
                        $link_data['context']
                    ),
                    'location'       => $url,
                    'current_value'  => (string) $result['status'],
                    'auto_fixable'   => 0,
                    'meta_data'      => [
                        'anchor_text'   => $link_data['text'] ?? '',
                        'link_type'     => $link_type,
                        'redirect_url'  => $result['redirect_url'],
                        'response_time' => $result['time'],
                    ],
                ] );
            }

            // Report slow links.
            if ( ! $is_broken && $result['time'] > 5.0 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => 'info',
                    'category'    => 'slow_link',
                    'title'       => 'Slow responding link',
                    'description' => sprintf(
                        'The URL "%s" took %.2f seconds to respond.',
                        $url,
                        $result['time']
                    ),
                    'location'      => $url,
                    'current_value' => sprintf( '%.2fs', $result['time'] ),
                ] );
            }

            // Report redirect chains.
            if ( ! empty( $result['redirect_url'] ) && $is_internal ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'       => 'warning',
                    'category'       => 'redirect',
                    'title'          => 'Internal link redirects',
                    'description'    => sprintf(
                        'The internal URL "%s" redirects to "%s". Update the link to point directly.',
                        $url,
                        $result['redirect_url']
                    ),
                    'location'       => $url,
                    'current_value'  => $url,
                    'suggested_value' => $result['redirect_url'],
                    'auto_fixable'   => 1,
                    'meta_data'      => [
                        'original_url' => $url,
                        'redirect_url' => $result['redirect_url'],
                    ],
                ] );
            }
        }
    }

    /**
     * Extract all <a> links from DOM.
     */
    private function extract_links( $dom, $site_url ) {
        $links = [];
        $anchors = $dom->getElementsByTagName( 'a' );

        foreach ( $anchors as $anchor ) {
            $href = $anchor->getAttribute( 'href' );
            if ( empty( $href ) ) {
                continue;
            }
            $links[] = [
                'url'     => $href,
                'text'    => trim( $anchor->textContent ),
                'context' => sprintf( '<a> tag: "%s"', trim( substr( $anchor->textContent, 0, 100 ) ) ),
                'type'    => 'anchor',
            ];
        }

        return $links;
    }

    /**
     * Extract all <img> sources from DOM.
     */
    private function extract_images( $dom, $site_url ) {
        $images = [];
        $img_tags = $dom->getElementsByTagName( 'img' );

        foreach ( $img_tags as $img ) {
            $src = $img->getAttribute( 'src' );
            if ( empty( $src ) ) {
                continue;
            }

            // Also check srcset.
            $images[] = [
                'url'     => $src,
                'text'    => $img->getAttribute( 'alt' ),
                'context' => sprintf( '<img> tag (alt: "%s")', $img->getAttribute( 'alt' ) ),
                'type'    => 'image',
            ];

            $srcset = $img->getAttribute( 'srcset' );
            if ( ! empty( $srcset ) ) {
                $srcset_urls = $this->parse_srcset( $srcset );
                foreach ( $srcset_urls as $srcset_url ) {
                    $images[] = [
                        'url'     => $srcset_url,
                        'text'    => '',
                        'context' => '<img srcset>',
                        'type'    => 'image',
                    ];
                }
            }
        }

        return $images;
    }

    private function parse_srcset( $srcset ) {
        $urls = [];
        $entries = explode( ',', $srcset );
        foreach ( $entries as $entry ) {
            $parts = preg_split( '/\s+/', trim( $entry ) );
            if ( ! empty( $parts[0] ) ) {
                $urls[] = $parts[0];
            }
        }
        return $urls;
    }

    /**
     * Check a URL and return status info.
     */
    private function check_url( $url, $timeout = 30 ) {
        $start = microtime( true );

        $response = wp_remote_head( $url, [
            'timeout'     => $timeout,
            'redirection' => 5,
            'sslverify'   => false,
            'user-agent'  => 'QualityAssurance-WP/1.0 (Link Checker)',
        ] );

        $time = microtime( true ) - $start;

        if ( is_wp_error( $response ) ) {
            // Try GET as fallback (some servers block HEAD).
            $response = wp_remote_get( $url, [
                'timeout'     => $timeout,
                'redirection' => 5,
                'sslverify'   => false,
                'user-agent'  => 'QualityAssurance-WP/1.0 (Link Checker)',
            ] );
            $time = microtime( true ) - $start;

            if ( is_wp_error( $response ) ) {
                return [
                    'status'       => 0,
                    'redirect_url' => '',
                    'time'         => $time,
                    'error'        => $response->get_error_message(),
                ];
            }
        }

        $status = wp_remote_retrieve_response_code( $response );
        $redirect_url = '';

        // Check for redirect.
        $headers = wp_remote_retrieve_headers( $response );
        if ( isset( $headers['location'] ) ) {
            $redirect_url = $headers['location'];
        }

        // Also detect redirect via URL change in response.
        if ( isset( $response['http_response'] ) ) {
            $final_url = $response['http_response']->get_response_object()->url ?? '';
            if ( $final_url && $final_url !== $url ) {
                $redirect_url = $final_url;
            }
        }

        return [
            'status'       => (int) $status,
            'redirect_url' => $redirect_url,
            'time'         => $time,
            'error'        => '',
        ];
    }

    private function should_skip_url( $url ) {
        if ( empty( $url ) || '#' === $url[0] ) {
            return true;
        }
        $skip_prefixes = [ 'javascript:', 'mailto:', 'tel:', 'data:', 'blob:' ];
        foreach ( $skip_prefixes as $prefix ) {
            if ( stripos( $url, $prefix ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    private function is_internal_url( $url, $site_url ) {
        if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
            return true;
        }
        return strpos( $url, $site_url ) === 0;
    }

    private function normalize_url( $url, $site_url ) {
        if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
            return rtrim( $site_url, '/' ) . $url;
        }
        if ( strpos( $url, '//' ) === 0 ) {
            return 'https:' . $url;
        }
        return $url;
    }

    private function is_ignored( $url, $ignored_urls ) {
        foreach ( $ignored_urls as $pattern ) {
            if ( fnmatch( $pattern, $url ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_broken_status( $status ) {
        if ( 0 === $status ) {
            return true; // Connection failed.
        }
        if ( $status >= 400 ) {
            return true;
        }
        return false;
    }
}
