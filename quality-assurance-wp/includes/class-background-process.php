<?php
namespace Flavor_QA;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * QA Background Process handler.
 * Processes scan tasks in the background using WP Background Processing.
 */
class Background_Process extends \WP_Background_Process {

    protected $prefix = 'flavor_qa';
    protected $action = 'scan_process';

    /**
     * Process a single scan task item.
     *
     * @param array $item { scan_id, type, post_id }
     * @return false Remove item from queue.
     */
    protected function task( $item ) {
        $scan_id = $item['scan_id'];
        $type    = $item['type'];
        $post_id = $item['post_id'];

        $plugin = Plugin::get_instance();
        $scanner = $plugin->get_scanner( $type );

        if ( $scanner ) {
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

        // Update progress.
        $db = new Database();
        $scan = $db->get_scan( $scan_id );
        if ( $scan ) {
            $db->update_scan( $scan_id, [
                'completed_items' => (int) $scan->completed_items + 1,
            ] );
        }

        return false;
    }

    /**
     * Complete processing.
     */
    protected function complete() {
        parent::complete();

        // Find the active scan and mark it complete.
        $db = new Database();
        $scan = $db->get_latest_scan();
        if ( $scan && 'running' === $scan->status ) {
            $db->complete_scan( $scan->id );
        }

        // Fire action for other plugins/modules to hook into.
        do_action( 'flavor_qa_scan_complete', $scan ? $scan->id : 0 );
    }
}
