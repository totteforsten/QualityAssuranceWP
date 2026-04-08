<?php
namespace Flavor_QA\Scanners;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Screenshot Scanner.
 * Captures page screenshots at multiple viewports using Puppeteer.
 */
class Screenshot_Scanner extends Scanner_Base {

    public function get_type() {
        return 'screenshots';
    }

    public function scan_post( $scan_id, $post_id ) {
        $url = get_permalink( $post_id );
        if ( ! $url ) {
            return;
        }

        $viewports = $this->settings['screenshot_viewports'] ?? [
            [ 'name' => 'mobile',  'width' => 375,  'height' => 812 ],
            [ 'name' => 'tablet',  'width' => 768,  'height' => 1024 ],
            [ 'name' => 'desktop', 'width' => 1440, 'height' => 900 ],
        ];

        foreach ( $viewports as $viewport ) {
            $this->capture_screenshot( $scan_id, $post_id, $url, $viewport );
        }
    }

    /**
     * Capture a screenshot at a specific viewport.
     */
    private function capture_screenshot( $scan_id, $post_id, $url, $viewport ) {
        $upload_dir = wp_upload_dir();
        $qa_dir = $upload_dir['basedir'] . '/flavor-qa-screenshots/' . $scan_id;

        if ( ! file_exists( $qa_dir ) ) {
            wp_mkdir_p( $qa_dir );
        }

        $filename = sprintf(
            'post-%d-%s-%dx%d.png',
            $post_id,
            sanitize_file_name( $viewport['name'] ),
            $viewport['width'],
            $viewport['height']
        );

        $file_path = $qa_dir . '/' . $filename;
        $file_url  = $upload_dir['baseurl'] . '/flavor-qa-screenshots/' . $scan_id . '/' . $filename;

        // Try Puppeteer first, then fallback methods.
        $success = $this->capture_with_puppeteer( $url, $file_path, $viewport );

        if ( ! $success ) {
            $success = $this->capture_with_api( $url, $file_path, $viewport );
        }

        if ( $success && file_exists( $file_path ) ) {
            $this->db->insert_screenshot( [
                'scan_id'         => $scan_id,
                'post_id'         => $post_id,
                'viewport'        => $viewport['name'],
                'viewport_width'  => $viewport['width'],
                'viewport_height' => $viewport['height'],
                'file_path'       => $file_path,
                'file_url'        => $file_url,
                'file_size'       => filesize( $file_path ),
            ] );
        } else {
            $this->report_issue( $scan_id, $post_id, [
                'severity'    => 'info',
                'category'    => 'screenshot_failed',
                'title'       => sprintf( 'Screenshot capture failed (%s)', $viewport['name'] ),
                'description' => sprintf(
                    'Could not capture screenshot at %dx%d for %s. Ensure Node.js and Puppeteer are installed.',
                    $viewport['width'],
                    $viewport['height'],
                    $url
                ),
            ] );
        }
    }

    /**
     * Capture using local Puppeteer (Node.js).
     */
    private function capture_with_puppeteer( $url, $output_path, $viewport ) {
        $node_path = $this->find_node_binary();
        if ( ! $node_path ) {
            return false;
        }

        $script_path = FLAVOR_QA_PLUGIN_DIR . 'assets/screenshot-capture.js';
        if ( ! file_exists( $script_path ) ) {
            return false;
        }

        // Check if puppeteer is installed.
        $node_modules = FLAVOR_QA_PLUGIN_DIR . 'node_modules';
        if ( ! is_dir( $node_modules . '/puppeteer' ) ) {
            return false;
        }

        $args = [
            escapeshellarg( $url ),
            escapeshellarg( $output_path ),
            (int) $viewport['width'],
            (int) $viewport['height'],
        ];

        $cmd = sprintf(
            '%s %s %s 2>&1',
            escapeshellcmd( $node_path ),
            escapeshellarg( $script_path ),
            implode( ' ', $args )
        );

        $output = [];
        $return_code = 0;
        exec( $cmd, $output, $return_code );

        return 0 === $return_code && file_exists( $output_path );
    }

    /**
     * Fallback: capture using a screenshot API service.
     * Users can configure their own API endpoint.
     */
    private function capture_with_api( $url, $output_path, $viewport ) {
        $api_url = $this->settings['screenshot_api_url'] ?? '';
        $api_key = $this->settings['screenshot_api_key'] ?? '';

        if ( empty( $api_url ) ) {
            return false;
        }

        $request_url = add_query_arg( [
            'url'    => rawurlencode( $url ),
            'width'  => $viewport['width'],
            'height' => $viewport['height'],
            'format' => 'png',
            'key'    => $api_key,
        ], $api_url );

        $response = wp_remote_get( $request_url, [
            'timeout'   => 60,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        if ( strpos( $content_type, 'image' ) === false ) {
            return false;
        }

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem->put_contents( $output_path, $body );
    }

    /**
     * Find the Node.js binary.
     */
    private function find_node_binary() {
        $custom = $this->settings['puppeteer_path'] ?? '';
        if ( ! empty( $custom ) && file_exists( $custom ) ) {
            return $custom;
        }

        $paths = [ '/usr/bin/node', '/usr/local/bin/node', '/opt/node/bin/node' ];
        foreach ( $paths as $path ) {
            if ( file_exists( $path ) && is_executable( $path ) ) {
                return $path;
            }
        }

        // Try which.
        $output = [];
        exec( 'which node 2>/dev/null', $output );
        if ( ! empty( $output[0] ) && file_exists( $output[0] ) ) {
            return $output[0];
        }

        return false;
    }

    /**
     * Clean up old screenshots.
     */
    public function cleanup_screenshots( $scan_id ) {
        $upload_dir = wp_upload_dir();
        $qa_dir = $upload_dir['basedir'] . '/flavor-qa-screenshots/' . $scan_id;

        if ( is_dir( $qa_dir ) ) {
            $files = glob( $qa_dir . '/*' );
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    wp_delete_file( $file );
                }
            }
            rmdir( $qa_dir );
        }
    }
}
