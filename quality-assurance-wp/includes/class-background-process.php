<?php
namespace Flavor_QA;

use Flavor_QA\Scanners\Scanner_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * QA Background Process handler.
 *
 * Each queue item is a post + all its scan types bundled together,
 * so we fetch the rendered HTML once per post instead of once per scanner.
 *
 * Item format: { scan_id: int, post_id: int, types: ['links','seo','responsive'] }
 */
class Background_Process extends \WP_Background_Process {

    protected $prefix = 'flavor_qa';
    protected $action = 'scan_process';

    /**
     * Allow longer execution per batch (50s instead of default 20s).
     * Most hosts allow 60s+ for admin-ajax.php requests.
     */
    protected $queue_lock_time = 60;

    /**
     * Process a single queue item (one post, all scanner types).
     */
    protected function task( $item ) {
        $scan_id = $item['scan_id'];
        $post_id = $item['post_id'];
        $types   = $item['types'] ?? [ $item['type'] ?? 'links' ];

        $plugin = Plugin::get_instance();

        foreach ( $types as $type ) {
            $scanner = $plugin->get_scanner( $type );
            if ( ! $scanner ) {
                continue;
            }

            try {
                $scanner->scan_post( $scan_id, $post_id );
            } catch ( \Exception $e ) {
                $db = new Database();
                $db->insert_issue( [
                    'scan_id'      => $scan_id,
                    'post_id'      => $post_id,
                    'scanner_type' => $type,
                    'severity'     => 'error',
                    'category'     => 'scan_error',
                    'title'        => 'Scan Error',
                    'description'  => $e->getMessage(),
                ] );
            }
        }

        // Flush the HTML cache for this post so memory stays low.
        Scanner_Base::flush_html_cache( $post_id );

        // Update progress: count as N completed items (one per scanner type).
        $db = new Database();
        $scan = $db->get_scan( $scan_id );
        if ( $scan ) {
            $db->update_scan( $scan_id, [
                'completed_items' => (int) $scan->completed_items + count( $types ),
            ] );
        }

        return false;
    }

    /**
     * Override time limit: 50 seconds per batch instead of 20.
     */
    protected function time_exceeded() {
        $finish = $this->start_time + apply_filters( $this->identifier . '_default_time_limit', 50 );
        return time() >= $finish;
    }

    /**
     * Complete processing.
     */
    protected function complete() {
        parent::complete();

        $db = new Database();
        $scan = $db->get_latest_scan();
        if ( $scan && 'running' === $scan->status ) {
            $db->complete_scan( $scan->id );
        }

        // Clean up the URL result cache from the link scanner.
        Scanners\Link_Scanner::clear_url_cache();

        do_action( 'flavor_qa_scan_complete', $scan ? $scan->id : 0 );
    }
}
