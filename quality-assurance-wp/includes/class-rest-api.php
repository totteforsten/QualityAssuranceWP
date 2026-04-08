<?php
namespace Flavor_QA;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API endpoints for the QA plugin.
 */
class Rest_API {

    private $namespace = 'flavor-qa/v1';
    private $plugin;

    public function __construct( Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    public function register_routes() {
        // Start a new scan.
        register_rest_route( $this->namespace, '/scan/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start_scan' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'types' => [
                    'type'    => 'array',
                    'default' => [ 'links', 'seo', 'responsive' ],
                    'items'   => [ 'type' => 'string' ],
                ],
                'post_ids' => [
                    'type'    => 'array',
                    'default' => [],
                    'items'   => [ 'type' => 'integer' ],
                ],
            ],
        ] );

        // Get scan status / progress.
        register_rest_route( $this->namespace, '/scan/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_scan' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        // Process next batch of posts for a scan (chunked AJAX scanning).
        register_rest_route( $this->namespace, '/scan/process-batch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'process_batch' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'scan_id' => [ 'type' => 'integer', 'required' => true ],
                'offset'  => [ 'type' => 'integer', 'default' => 0 ],
                'limit'   => [ 'type' => 'integer', 'default' => 5 ],
            ],
        ] );

        // Get scan list.
        register_rest_route( $this->namespace, '/scans', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_scans' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 20 ],
            ],
        ] );

        // Delete a scan.
        register_rest_route( $this->namespace, '/scan/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_scan' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        // Get issues.
        register_rest_route( $this->namespace, '/issues', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_issues' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'scan_id'      => [ 'type' => 'integer', 'default' => 0 ],
                'post_id'      => [ 'type' => 'integer', 'default' => 0 ],
                'scanner_type' => [ 'type' => 'string', 'default' => '' ],
                'severity'     => [ 'type' => 'string', 'default' => '' ],
                'is_resolved'  => [ 'type' => 'integer' ],
                'page'         => [ 'type' => 'integer', 'default' => 1 ],
                'per_page'     => [ 'type' => 'integer', 'default' => 50 ],
            ],
        ] );

        // Get issue summary.
        register_rest_route( $this->namespace, '/issues/summary/(?P<scan_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_issue_summary' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        // Resolve issue(s).
        register_rest_route( $this->namespace, '/issues/resolve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'resolve_issues' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'ids' => [
                    'type'     => 'array',
                    'required' => true,
                    'items'    => [ 'type' => 'integer' ],
                ],
            ],
        ] );

        // Apply auto-fix.
        register_rest_route( $this->namespace, '/issues/fix', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'apply_fix' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'issue_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        // Bulk fix issues.
        register_rest_route( $this->namespace, '/issues/bulk-fix', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'bulk_fix' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'ids' => [
                    'type'     => 'array',
                    'required' => true,
                    'items'    => [ 'type' => 'integer' ],
                ],
            ],
        ] );

        // Get broken links.
        register_rest_route( $this->namespace, '/links', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_links' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'scan_id'   => [ 'type' => 'integer', 'default' => 0 ],
                'is_broken' => [ 'type' => 'integer' ],
                'page'      => [ 'type' => 'integer', 'default' => 1 ],
                'per_page'  => [ 'type' => 'integer', 'default' => 50 ],
            ],
        ] );

        // Get screenshots.
        register_rest_route( $this->namespace, '/screenshots', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_screenshots' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'scan_id'  => [ 'type' => 'integer', 'default' => 0 ],
                'post_id'  => [ 'type' => 'integer', 'default' => 0 ],
                'viewport' => [ 'type' => 'string', 'default' => '' ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
            ],
        ] );

        // Get/Update settings.
        register_rest_route( $this->namespace, '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => [ $this, 'check_admin_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'update_settings' ],
                'permission_callback' => [ $this, 'check_admin_permission' ],
            ],
        ] );

        // Get dashboard stats.
        register_rest_route( $this->namespace, '/dashboard', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_dashboard' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        // Update page builder element.
        register_rest_route( $this->namespace, '/builder/update', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_builder_element' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'post_id'      => [ 'type' => 'integer', 'required' => true ],
                'element_id'   => [ 'type' => 'string',  'required' => true ],
                'builder'      => [ 'type' => 'string',  'required' => true ],
                'new_settings' => [ 'type' => 'object',  'required' => true ],
            ],
        ] );

        // Install Puppeteer (npm install).
        register_rest_route( $this->namespace, '/screenshots/install', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'install_puppeteer' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );
    }

    public function check_admin_permission() {
        return current_user_can( 'manage_options' );
    }

    // ─── Callbacks ──────────────────────────────────────────

    public function start_scan( $request ) {
        $types    = $request->get_param( 'types' );
        $post_ids = $request->get_param( 'post_ids' );

        $valid_types = [ 'links', 'seo', 'responsive', 'screenshots' ];
        $types = array_values( array_intersect( $types, $valid_types ) );

        if ( empty( $types ) ) {
            return new \WP_Error( 'invalid_types', 'No valid scan types provided.', [ 'status' => 400 ] );
        }

        // Clear caches from previous scans.
        Scanners\Link_Scanner::clear_url_cache();
        Scanners\Scanner_Base::flush_html_cache();

        // Create scan record. The frontend will drive processing via process-batch.
        $db = new Database();
        $scan_id = $db->create_scan( $types );

        if ( empty( $post_ids ) ) {
            $post_ids = $this->plugin->get_all_scannable_posts();
        }

        $total_items = count( $post_ids ) * count( $types );
        $db->update_scan( $scan_id, [ 'total_items' => $total_items ] );

        // Store the post list and types so process-batch can pick them up.
        update_option( 'flavor_qa_scan_' . $scan_id . '_posts', $post_ids, false );
        update_option( 'flavor_qa_scan_' . $scan_id . '_types', $types, false );

        return rest_ensure_response( [
            'scan_id'     => $scan_id,
            'total_posts' => count( $post_ids ),
            'total_items' => $total_items,
            'types'       => $types,
        ] );
    }

    /**
     * Process a batch of posts for a scan.
     * Called repeatedly by the frontend JS until all posts are done.
     * Returns a log of what was checked for real-time display.
     */
    public function process_batch( $request ) {
        // Register shutdown handler to catch fatal errors and return useful info.
        $error_log = [];
        register_shutdown_function( function() use ( &$error_log ) {
            $error = error_get_last();
            if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
                // Clear any output buffer.
                while ( ob_get_level() ) {
                    ob_end_clean();
                }
                $msg = sprintf( '%s in %s:%d', $error['message'], basename( $error['file'] ), $error['line'] );
                wp_send_json_error( [
                    'message'   => 'PHP Fatal: ' . $msg,
                    'php_error' => $error,
                ], 500 );
            }
        } );

        $scan_id = (int) $request->get_param( 'scan_id' );
        $offset  = (int) $request->get_param( 'offset' );
        $limit   = (int) $request->get_param( 'limit' );

        $db   = new Database();
        $scan = $db->get_scan( $scan_id );

        if ( ! $scan ) {
            return new \WP_Error( 'not_found', 'Scan not found.', [ 'status' => 404 ] );
        }

        $post_ids = get_option( 'flavor_qa_scan_' . $scan_id . '_posts', [] );
        $types    = get_option( 'flavor_qa_scan_' . $scan_id . '_types', [] );

        if ( empty( $post_ids ) || empty( $types ) ) {
            return new \WP_Error( 'invalid_scan', 'Scan data not found.', [ 'status' => 400 ] );
        }

        // Get the batch of posts for this chunk.
        $batch = array_slice( $post_ids, $offset, $limit );
        $log   = [];
        $items_done = 0;

        // Increase time limit for this request.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        foreach ( $batch as $post_id ) {
            $post_title = get_the_title( $post_id );

            $log[] = [
                'type'    => 'start',
                'message' => sprintf( 'Scanning: %s (#%d)', $post_title, $post_id ),
            ];

            foreach ( $types as $type ) {
                $scanner = $this->plugin->get_scanner( $type );
                if ( ! $scanner ) {
                    continue;
                }

                $start_time = microtime( true );

                // Catch both Exception AND Error (PHP 7+ fatal errors like TypeError).
                try {
                    $scanner->scan_post( $scan_id, $post_id );
                    $elapsed = round( microtime( true ) - $start_time, 2 );

                    $log[] = [
                        'type'    => 'done',
                        'message' => sprintf( '  [%s] completed in %ss', $type, $elapsed ),
                    ];
                } catch ( \Throwable $e ) {
                    $db->insert_issue( [
                        'scan_id'      => $scan_id,
                        'post_id'      => $post_id,
                        'scanner_type' => $type,
                        'severity'     => 'error',
                        'category'     => 'scan_error',
                        'title'        => 'Scan Error',
                        'description'  => $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine(),
                    ] );

                    $log[] = [
                        'type'    => 'error',
                        'message' => sprintf(
                            '  [%s] ERROR: %s (in %s:%d)',
                            $type,
                            $e->getMessage(),
                            basename( $e->getFile() ),
                            $e->getLine()
                        ),
                    ];
                }

                $items_done++;
            }

            // Flush HTML cache per post to keep memory low.
            Scanners\Scanner_Base::flush_html_cache( $post_id );
        }

        // Update scan progress.
        $completed = (int) $scan->completed_items + $items_done;
        $db->update_scan( $scan_id, [ 'completed_items' => $completed ] );

        $total       = (int) $scan->total_items;
        $is_complete = ( $offset + $limit ) >= count( $post_ids );

        if ( $is_complete ) {
            $db->complete_scan( $scan_id );
            Scanners\Link_Scanner::clear_url_cache();

            // Clean up stored post list.
            delete_option( 'flavor_qa_scan_' . $scan_id . '_posts' );
            delete_option( 'flavor_qa_scan_' . $scan_id . '_types' );

            $log[] = [
                'type'    => 'complete',
                'message' => sprintf( 'Scan complete! %d issues found.', $db->count_issues_for_scan( $scan_id ) ),
            ];
        }

        return rest_ensure_response( [
            'scan_id'        => $scan_id,
            'offset'         => $offset,
            'processed'      => count( $batch ),
            'completed_items' => $completed,
            'total_items'    => $total,
            'progress'       => $total > 0 ? round( ( $completed / $total ) * 100, 1 ) : 0,
            'is_complete'    => $is_complete,
            'log'            => $log,
        ] );
    }

    public function get_scan( $request ) {
        $db   = new Database();
        $scan = $db->get_scan( (int) $request['id'] );

        if ( ! $scan ) {
            return new \WP_Error( 'not_found', 'Scan not found.', [ 'status' => 404 ] );
        }

        $scan->scan_types = json_decode( $scan->scan_types, true );
        $scan->progress   = $scan->total_items > 0
            ? round( ( $scan->completed_items / $scan->total_items ) * 100, 1 )
            : 0;

        return rest_ensure_response( $scan );
    }

    public function get_scans( $request ) {
        $db = new Database();
        return rest_ensure_response(
            $db->get_scans( [
                'page'     => $request->get_param( 'page' ),
                'per_page' => $request->get_param( 'per_page' ),
            ] )
        );
    }

    public function delete_scan( $request ) {
        $db = new Database();
        $scan = $db->get_scan( (int) $request['id'] );

        if ( ! $scan ) {
            return new \WP_Error( 'not_found', 'Scan not found.', [ 'status' => 404 ] );
        }

        // Clean up screenshots.
        $scanner = new Scanners\Screenshot_Scanner();
        $scanner->cleanup_screenshots( (int) $request['id'] );

        $db->delete_scan_data( (int) $request['id'] );

        return rest_ensure_response( [ 'deleted' => true ] );
    }

    public function get_issues( $request ) {
        $db = new Database();
        $issues = $db->get_issues( [
            'scan_id'      => $request->get_param( 'scan_id' ),
            'post_id'      => $request->get_param( 'post_id' ),
            'scanner_type' => $request->get_param( 'scanner_type' ),
            'severity'     => $request->get_param( 'severity' ),
            'is_resolved'  => $request->get_param( 'is_resolved' ),
            'page'         => $request->get_param( 'page' ),
            'per_page'     => $request->get_param( 'per_page' ),
        ] );

        // Enrich with post info.
        foreach ( $issues as &$issue ) {
            if ( $issue->post_id ) {
                $issue->post_title = get_the_title( $issue->post_id );
                $issue->post_url   = get_permalink( $issue->post_id );
                $issue->edit_url   = get_edit_post_link( $issue->post_id, 'raw' );
            }
            if ( $issue->meta_data ) {
                $issue->meta_data = json_decode( $issue->meta_data, true );
            }
        }

        return rest_ensure_response( $issues );
    }

    public function get_issue_summary( $request ) {
        $db = new Database();
        return rest_ensure_response(
            $db->get_issue_summary( (int) $request['scan_id'] )
        );
    }

    public function resolve_issues( $request ) {
        $db  = new Database();
        $ids = array_map( 'intval', $request->get_param( 'ids' ) );
        $db->bulk_resolve_issues( $ids );
        return rest_ensure_response( [ 'resolved' => count( $ids ) ] );
    }

    public function apply_fix( $request ) {
        $db = new Database();
        $issue_id = (int) $request->get_param( 'issue_id' );

        $issues = $db->get_issues( [ 'scan_id' => 0 ] ); // Get all.
        // Find specific issue.
        global $wpdb;
        $issue = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}flavor_qa_issues WHERE id = %d",
                $issue_id
            )
        );

        if ( ! $issue ) {
            return new \WP_Error( 'not_found', 'Issue not found.', [ 'status' => 404 ] );
        }

        if ( ! $issue->auto_fixable ) {
            return new \WP_Error( 'not_fixable', 'This issue cannot be auto-fixed.', [ 'status' => 400 ] );
        }

        $result = $this->attempt_fix( $issue );
        if ( $result ) {
            $db->resolve_issue( $issue_id );
            return rest_ensure_response( [ 'fixed' => true, 'message' => 'Issue fixed successfully.' ] );
        }

        return new \WP_Error( 'fix_failed', 'Auto-fix failed. Please fix manually.', [ 'status' => 500 ] );
    }

    public function bulk_fix( $request ) {
        $db    = new Database();
        $ids   = array_map( 'intval', $request->get_param( 'ids' ) );
        $fixed = 0;
        $failed = 0;

        global $wpdb;
        foreach ( $ids as $id ) {
            $issue = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}flavor_qa_issues WHERE id = %d AND auto_fixable = 1",
                    $id
                )
            );

            if ( $issue && $this->attempt_fix( $issue ) ) {
                $db->resolve_issue( $id );
                $fixed++;
            } else {
                $failed++;
            }
        }

        return rest_ensure_response( [
            'fixed'  => $fixed,
            'failed' => $failed,
        ] );
    }

    public function get_links( $request ) {
        $db = new Database();
        return rest_ensure_response(
            $db->get_links( [
                'scan_id'   => $request->get_param( 'scan_id' ),
                'is_broken' => $request->get_param( 'is_broken' ),
                'page'      => $request->get_param( 'page' ),
                'per_page'  => $request->get_param( 'per_page' ),
            ] )
        );
    }

    public function get_screenshots( $request ) {
        $db = new Database();
        $screenshots = $db->get_screenshots( [
            'scan_id'  => $request->get_param( 'scan_id' ),
            'post_id'  => $request->get_param( 'post_id' ),
            'viewport' => $request->get_param( 'viewport' ),
            'page'     => $request->get_param( 'page' ),
        ] );

        // Enrich with post info.
        foreach ( $screenshots as &$ss ) {
            $ss->post_title = get_the_title( $ss->post_id );
            $ss->post_url   = get_permalink( $ss->post_id );
        }

        return rest_ensure_response( $screenshots );
    }

    public function get_settings( $request ) {
        return rest_ensure_response( get_option( 'flavor_qa_settings', [] ) );
    }

    public function update_settings( $request ) {
        $settings = $request->get_json_params();
        $current  = get_option( 'flavor_qa_settings', [] );
        $merged   = array_merge( $current, $settings );
        update_option( 'flavor_qa_settings', $merged );
        return rest_ensure_response( $merged );
    }

    public function get_dashboard( $request ) {
        $db = new Database();
        $latest = $db->get_latest_scan();

        $data = [
            'latest_scan' => null,
            'summary'     => [],
            'totals'      => [
                'scans'      => 0,
                'issues'     => 0,
                'broken_links' => 0,
            ],
        ];

        if ( $latest ) {
            $latest->scan_types = json_decode( $latest->scan_types, true );
            $latest->progress   = $latest->total_items > 0
                ? round( ( $latest->completed_items / $latest->total_items ) * 100, 1 )
                : 0;

            $data['latest_scan'] = $latest;
            $data['summary']     = $db->get_issue_summary( $latest->id );
            $data['totals']['issues']       = $db->count_issues_for_scan( $latest->id );
            $data['totals']['broken_links'] = $db->count_broken_links( $latest->id );
        }

        global $wpdb;
        $data['totals']['scans'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}flavor_qa_scans"
        );

        return rest_ensure_response( $data );
    }

    public function update_builder_element( $request ) {
        $post_id      = (int) $request->get_param( 'post_id' );
        $element_id   = sanitize_text_field( $request->get_param( 'element_id' ) );
        $builder_type = sanitize_text_field( $request->get_param( 'builder' ) );
        $new_settings = $request->get_param( 'new_settings' );

        $parser = $this->plugin->get_builder_parser( $builder_type );
        if ( ! $parser ) {
            return new \WP_Error( 'invalid_builder', 'Unknown builder type.', [ 'status' => 400 ] );
        }

        $result = $parser->update_element_settings( $post_id, $element_id, $new_settings );
        if ( $result ) {
            return rest_ensure_response( [ 'updated' => true ] );
        }

        return new \WP_Error( 'update_failed', 'Could not update element.', [ 'status' => 500 ] );
    }

    public function install_puppeteer( $request ) {
        $plugin_dir = FLAVOR_QA_PLUGIN_DIR;
        $package_json = $plugin_dir . 'package.json';

        if ( ! file_exists( $package_json ) ) {
            return new \WP_Error( 'missing_package', 'package.json not found.', [ 'status' => 500 ] );
        }

        // Find npm.
        $npm_path = '';
        $possible = [ '/usr/bin/npm', '/usr/local/bin/npm' ];
        foreach ( $possible as $p ) {
            if ( file_exists( $p ) ) {
                $npm_path = $p;
                break;
            }
        }
        if ( ! $npm_path ) {
            exec( 'which npm 2>/dev/null', $which_output );
            $npm_path = ! empty( $which_output[0] ) ? $which_output[0] : '';
        }

        if ( empty( $npm_path ) ) {
            return new \WP_Error( 'npm_not_found', 'npm not found on this server. Install Node.js first.', [ 'status' => 500 ] );
        }

        $cmd = sprintf(
            'cd %s && %s install --production 2>&1',
            escapeshellarg( $plugin_dir ),
            escapeshellcmd( $npm_path )
        );

        $output = [];
        $return_code = 0;
        exec( $cmd, $output, $return_code );

        $output_str = implode( "\n", $output );

        if ( 0 !== $return_code ) {
            return new \WP_Error( 'install_failed', 'npm install failed: ' . $output_str, [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'success' => true,
            'message' => 'Puppeteer installed successfully.',
            'output'  => $output_str,
        ] );
    }

    // ─── Auto-fix logic ─────────────────────────────────────

    private function attempt_fix( $issue ) {
        $meta = json_decode( $issue->meta_data ?? '{}', true ) ?: [];

        switch ( $issue->category ) {
            case 'redirect':
                return $this->fix_redirect_link( $issue, $meta );

            case 'mixed_content':
                return $this->fix_mixed_content( $issue );

            case 'fixed_width':
            case 'large_spacing':
            case 'typography_responsive':
            case 'narrow_column':
                return $this->fix_builder_responsive( $issue, $meta );

            default:
                return false;
        }
    }

    private function fix_redirect_link( $issue, $meta ) {
        if ( empty( $meta['original_url'] ) || empty( $meta['redirect_url'] ) ) {
            return false;
        }

        $post = get_post( $issue->post_id );
        if ( ! $post ) {
            return false;
        }

        $content = $post->post_content;
        $updated = str_replace( $meta['original_url'], $meta['redirect_url'], $content );

        if ( $updated !== $content ) {
            wp_update_post( [
                'ID'           => $issue->post_id,
                'post_content' => $updated,
            ] );
            return true;
        }

        // Also try Elementor/Breakdance data.
        return $this->fix_link_in_builder( $issue->post_id, $meta['original_url'], $meta['redirect_url'] );
    }

    private function fix_mixed_content( $issue ) {
        $post = get_post( $issue->post_id );
        if ( ! $post ) {
            return false;
        }

        $content = $post->post_content;
        $site_url = home_url();
        $http_url = str_replace( 'https://', 'http://', $site_url );

        $updated = str_replace( $http_url, $site_url, $content );

        if ( $updated !== $content ) {
            wp_update_post( [
                'ID'           => $issue->post_id,
                'post_content' => $updated,
            ] );
            return true;
        }

        return false;
    }

    private function fix_builder_responsive( $issue, $meta ) {
        $builder = $meta['builder'] ?? '';
        $element_id = $meta['element_id'] ?? '';

        if ( empty( $builder ) || empty( $element_id ) ) {
            return false;
        }

        $parser = $this->plugin->get_builder_parser( $builder );
        if ( ! $parser ) {
            return false;
        }

        // Generate responsive fix based on category.
        $fix_settings = $this->generate_responsive_fix( $issue, $meta );
        if ( empty( $fix_settings ) ) {
            return false;
        }

        return $parser->update_element_settings( $issue->post_id, $element_id, $fix_settings );
    }

    private function generate_responsive_fix( $issue, $meta ) {
        $setting = $meta['setting'] ?? '';

        switch ( $issue->category ) {
            case 'typography_responsive':
                // Set mobile font size to ~60% of desktop.
                if ( preg_match( '/(\d+)/', $issue->current_value, $m ) ) {
                    $mobile_size = max( 16, intdiv( (int) $m[1], 2 ) + 4 );
                    return [
                        $setting . '_mobile' => [
                            'size' => $mobile_size,
                            'unit' => 'px',
                        ],
                    ];
                }
                break;

            case 'large_spacing':
                $dir = $meta['direction'] ?? 'top';
                if ( preg_match( '/(-?\d+)/', $issue->current_value, $m ) ) {
                    $mobile_val = intdiv( (int) $m[1], 2 );
                    return [
                        $setting . '_mobile' => [
                            $dir => (string) $mobile_val,
                            'unit' => 'px',
                        ],
                    ];
                }
                break;

            case 'narrow_column':
                return [
                    '_inline_size_mobile' => 100,
                ];
        }

        return [];
    }

    private function fix_link_in_builder( $post_id, $old_url, $new_url ) {
        // Try Elementor.
        $elementor = new Builders\Elementor_Parser();
        if ( $elementor->is_active( $post_id ) ) {
            $data = $elementor->get_parsed_data( $post_id );
            $json = wp_json_encode( $data );
            $updated = str_replace(
                str_replace( '/', '\\/', $old_url ),
                str_replace( '/', '\\/', $new_url ),
                $json
            );
            if ( $updated !== $json ) {
                $elementor->update_data( $post_id, json_decode( $updated, true ) );
                return true;
            }
        }

        // Try Breakdance.
        $breakdance = new Builders\Breakdance_Parser();
        if ( $breakdance->is_active( $post_id ) ) {
            $data = $breakdance->get_parsed_data( $post_id );
            $json = wp_json_encode( $data );
            $updated = str_replace(
                str_replace( '/', '\\/', $old_url ),
                str_replace( '/', '\\/', $new_url ),
                $json
            );
            if ( $updated !== $json ) {
                $breakdance->update_data( $post_id, json_decode( $updated, true ) );
                return true;
            }
        }

        return false;
    }
}
