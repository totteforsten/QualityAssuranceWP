<?php
namespace Flavor_QA\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dead Link Scanner.
 * Crawls post content and checks links in parallel using curl_multi.
 */
class Link_Scanner extends Scanner_Base {

    /**
     * Cross-scan URL result cache.
     * If the same URL appears on multiple pages, we check it once.
     * Stored as transient so it persists across background process batches.
     */
    private const CACHE_KEY = 'flavor_qa_link_cache';

    /** Max concurrent curl handles. */
    private const PARALLEL_LIMIT = 10;

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

        $site_url       = home_url();
        $ignored_urls   = $this->settings['ignored_urls'] ?? [];
        $timeout        = (int) ( $this->settings['link_timeout'] ?? 15 );
        $check_external = $this->settings['link_check_external'] ?? true;

        // Extract all URLs from the page.
        $links  = $this->extract_links( $dom );
        $images = $this->extract_images( $dom );
        $all    = array_merge( $links, $images );

        // Normalize, filter, and deduplicate URLs for this page.
        $to_check = [];
        $url_meta = []; // url => [link_data entries]

        foreach ( $all as $link_data ) {
            $url = $link_data['url'];

            if ( $this->should_skip_url( $url ) ) {
                continue;
            }

            $url = $this->normalize_url( $url, $site_url );

            if ( $this->is_ignored( $url, $ignored_urls ) ) {
                continue;
            }

            $is_internal = $this->is_internal_url( $url, $site_url );
            if ( ! $check_external && ! $is_internal ) {
                continue;
            }

            // Group by URL so we check each URL only once per page.
            if ( ! isset( $url_meta[ $url ] ) ) {
                $url_meta[ $url ] = [];
                $to_check[] = $url;
            }
            $url_meta[ $url ][] = $link_data;
        }

        // Load the cross-scan URL cache (results already checked for other pages).
        $result_cache = get_transient( self::CACHE_KEY ) ?: [];

        // Split into cached (already checked) and uncached (need checking).
        $uncached = [];
        $cached   = [];
        foreach ( $to_check as $url ) {
            if ( isset( $result_cache[ $url ] ) ) {
                $cached[ $url ] = $result_cache[ $url ];
            } else {
                $uncached[] = $url;
            }
        }

        // Check uncached URLs in parallel batches.
        $new_results = [];
        if ( ! empty( $uncached ) ) {
            $new_results = $this->check_urls_parallel( $uncached, $timeout );
            // Merge into cache.
            foreach ( $new_results as $url => $result ) {
                $result_cache[ $url ] = $result;
            }
            // Keep cache size reasonable (last 2000 URLs).
            if ( count( $result_cache ) > 2000 ) {
                $result_cache = array_slice( $result_cache, -2000, null, true );
            }
            set_transient( self::CACHE_KEY, $result_cache, HOUR_IN_SECONDS );
        }

        // Merge cached + new results and process all.
        $all_results = array_merge( $cached, $new_results );

        foreach ( $to_check as $url ) {
            if ( ! isset( $all_results[ $url ] ) ) {
                continue;
            }

            $result      = $all_results[ $url ];
            $is_broken   = $this->is_broken_status( $result['status'] );
            $is_internal = $this->is_internal_url( $url, $site_url );
            $link_type   = $is_internal ? 'internal' : 'external';
            $first_link  = $url_meta[ $url ][0] ?? [];

            // Store one link record per unique URL per page.
            $this->db->insert_link( [
                'scan_id'       => $scan_id,
                'post_id'       => $post_id,
                'url'           => $url,
                'anchor_text'   => substr( $first_link['text'] ?? '', 0, 500 ),
                'link_type'     => $link_type,
                'http_status'   => $result['status'],
                'redirect_url'  => $result['redirect_url'],
                'response_time' => $result['time'],
                'is_broken'     => $is_broken ? 1 : 0,
                'context'       => $first_link['context'] ?? '',
                'checked_at'    => current_time( 'mysql' ),
            ] );

            if ( $is_broken ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'    => $is_internal ? 'error' : 'warning',
                    'category'    => 'broken_link',
                    'title'       => sprintf( 'Broken %s link (HTTP %s)', $link_type, $result['status'] ?: 'timeout' ),
                    'description' => sprintf(
                        'The URL "%s" returned HTTP status %s. Found in: %s',
                        $url,
                        $result['status'] ?: 'timeout/error',
                        $first_link['context'] ?? ''
                    ),
                    'location'     => $url,
                    'current_value' => (string) $result['status'],
                    'auto_fixable' => 0,
                    'meta_data'    => [
                        'anchor_text'   => $first_link['text'] ?? '',
                        'link_type'     => $link_type,
                        'redirect_url'  => $result['redirect_url'],
                        'response_time' => $result['time'],
                    ],
                ] );
            }

            // Slow links.
            if ( ! $is_broken && $result['time'] > 5.0 ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'      => 'info',
                    'category'      => 'slow_link',
                    'title'         => 'Slow responding link',
                    'description'   => sprintf( 'The URL "%s" took %.2fs to respond.', $url, $result['time'] ),
                    'location'      => $url,
                    'current_value' => sprintf( '%.2fs', $result['time'] ),
                ] );
            }

            // Internal redirects.
            if ( ! empty( $result['redirect_url'] ) && $is_internal ) {
                $this->report_issue( $scan_id, $post_id, [
                    'severity'        => 'warning',
                    'category'        => 'redirect',
                    'title'           => 'Internal link redirects',
                    'description'     => sprintf( '"%s" redirects to "%s". Update the link directly.', $url, $result['redirect_url'] ),
                    'location'        => $url,
                    'current_value'   => $url,
                    'suggested_value' => $result['redirect_url'],
                    'auto_fixable'    => 1,
                    'meta_data'       => [
                        'original_url' => $url,
                        'redirect_url' => $result['redirect_url'],
                    ],
                ] );
            }
        }
    }

    /**
     * Check multiple URLs in parallel using curl_multi.
     * Processes in batches of PARALLEL_LIMIT.
     *
     * @param array $urls   List of URLs to check.
     * @param int   $timeout Timeout per request in seconds.
     * @return array URL => result map.
     */
    private function check_urls_parallel( $urls, $timeout = 15 ) {
        $results = [];

        // Fall back to sequential wp_remote_head if curl_multi is unavailable.
        if ( ! function_exists( 'curl_multi_init' ) ) {
            return $this->check_urls_sequential( $urls, $timeout );
        }

        // Process in batches.
        $batches = array_chunk( $urls, self::PARALLEL_LIMIT );

        foreach ( $batches as $batch ) {
            $batch_results = $this->curl_multi_check( $batch, $timeout );
            $results = array_merge( $results, $batch_results );
        }

        return $results;
    }

    /**
     * Execute a batch of URL checks using curl_multi.
     */
    private function curl_multi_check( $urls, $timeout ) {
        $results = [];
        $mh = curl_multi_init();
        $handles = [];

        foreach ( $urls as $url ) {
            $ch = curl_init();
            curl_setopt_array( $ch, [
                CURLOPT_URL            => $url,
                CURLOPT_NOBODY         => true,          // HEAD request first.
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min( 10, $timeout ),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'QualityAssurance-WP/1.0 (Link Checker)',
                CURLOPT_HEADER         => false,
                CURLOPT_ENCODING       => '',            // Accept compressed.
            ] );
            curl_multi_add_handle( $mh, $ch );
            $handles[ (int) $ch ] = [
                'handle' => $ch,
                'url'    => $url,
                'start'  => microtime( true ),
            ];
        }

        // Execute all handles.
        $running = 0;
        do {
            $status = curl_multi_exec( $mh, $running );
            if ( $running > 0 ) {
                curl_multi_select( $mh, 1 );
            }
        } while ( $running > 0 && $status === CURLM_OK );

        // Collect results.
        $retry_with_get = [];

        foreach ( $handles as $id => $info ) {
            $ch   = $info['handle'];
            $url  = $info['url'];
            $time = microtime( true ) - $info['start'];

            $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $final_url = curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
            $error     = curl_error( $ch );

            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );

            // Some servers block HEAD - queue GET retry for those.
            if ( 0 === $http_code || 405 === $http_code || 501 === $http_code ) {
                $retry_with_get[] = $url;
                continue;
            }

            $redirect_url = ( $final_url && $final_url !== $url ) ? $final_url : '';

            $results[ $url ] = [
                'status'       => $http_code,
                'redirect_url' => $redirect_url,
                'time'         => $time,
                'error'        => $error,
            ];
        }

        curl_multi_close( $mh );

        // Retry failures with GET.
        if ( ! empty( $retry_with_get ) ) {
            $get_results = $this->curl_multi_get( $retry_with_get, $timeout );
            $results = array_merge( $results, $get_results );
        }

        return $results;
    }

    /**
     * Retry URLs with GET requests via curl_multi.
     */
    private function curl_multi_get( $urls, $timeout ) {
        $results = [];
        $mh = curl_multi_init();
        $handles = [];

        foreach ( $urls as $url ) {
            $ch = curl_init();
            curl_setopt_array( $ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min( 10, $timeout ),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'QualityAssurance-WP/1.0 (Link Checker)',
                CURLOPT_ENCODING       => '',
                // Only download headers + first 1KB to save bandwidth.
                CURLOPT_RANGE          => '0-1024',
            ] );
            curl_multi_add_handle( $mh, $ch );
            $handles[ (int) $ch ] = [
                'handle' => $ch,
                'url'    => $url,
                'start'  => microtime( true ),
            ];
        }

        $running = 0;
        do {
            $status = curl_multi_exec( $mh, $running );
            if ( $running > 0 ) {
                curl_multi_select( $mh, 1 );
            }
        } while ( $running > 0 && $status === CURLM_OK );

        foreach ( $handles as $id => $info ) {
            $ch   = $info['handle'];
            $url  = $info['url'];
            $time = microtime( true ) - $info['start'];

            $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $final_url = curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
            $error     = curl_error( $ch );

            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );

            $redirect_url = ( $final_url && $final_url !== $url ) ? $final_url : '';

            $results[ $url ] = [
                'status'       => $http_code,
                'redirect_url' => $redirect_url,
                'time'         => $time,
                'error'        => $error,
            ];
        }

        curl_multi_close( $mh );
        return $results;
    }

    // ─── URL extraction ─────────────────────────────────────

    private function extract_links( $dom ) {
        $links = [];
        foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
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

    private function extract_images( $dom ) {
        $images = [];
        foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
            $src = $img->getAttribute( 'src' );
            if ( empty( $src ) ) {
                continue;
            }

            $images[] = [
                'url'     => $src,
                'text'    => $img->getAttribute( 'alt' ),
                'context' => sprintf( '<img> tag (alt: "%s")', $img->getAttribute( 'alt' ) ),
                'type'    => 'image',
            ];

            $srcset = $img->getAttribute( 'srcset' );
            if ( ! empty( $srcset ) ) {
                foreach ( $this->parse_srcset( $srcset ) as $srcset_url ) {
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
        foreach ( explode( ',', $srcset ) as $entry ) {
            $parts = preg_split( '/\s+/', trim( $entry ) );
            if ( ! empty( $parts[0] ) ) {
                $urls[] = $parts[0];
            }
        }
        return $urls;
    }

    // ─── URL helpers ────────────────────────────────────────

    private function should_skip_url( $url ) {
        if ( empty( $url ) || '#' === $url[0] ) {
            return true;
        }
        $skip = [ 'javascript:', 'mailto:', 'tel:', 'data:', 'blob:' ];
        foreach ( $skip as $prefix ) {
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
        return 0 === $status || $status >= 400;
    }

    /**
     * Fallback: check URLs one at a time using wp_remote_head.
     * Used when curl_multi is not available.
     */
    private function check_urls_sequential( $urls, $timeout ) {
        $results = [];
        foreach ( $urls as $url ) {
            $start = microtime( true );

            $response = wp_remote_head( $url, [
                'timeout'     => min( $timeout, 10 ),
                'redirection' => 5,
                'sslverify'   => false,
                'user-agent'  => 'QualityAssurance-WP/1.0 (Link Checker)',
            ] );

            $time = microtime( true ) - $start;

            if ( is_wp_error( $response ) ) {
                $results[ $url ] = [
                    'status'       => 0,
                    'redirect_url' => '',
                    'time'         => $time,
                    'error'        => $response->get_error_message(),
                ];
                continue;
            }

            $status    = (int) wp_remote_retrieve_response_code( $response );
            $final_url = '';

            if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) ) {
                $resp_obj = $response['http_response']->get_response_object();
                if ( $resp_obj && isset( $resp_obj->url ) && $resp_obj->url !== $url ) {
                    $final_url = $resp_obj->url;
                }
            }

            $results[ $url ] = [
                'status'       => $status,
                'redirect_url' => $final_url,
                'time'         => $time,
                'error'        => '',
            ];
        }
        return $results;
    }

    /**
     * Clear the URL result cache (called when starting a new scan).
     */
    public static function clear_url_cache() {
        delete_transient( self::CACHE_KEY );
    }
}
