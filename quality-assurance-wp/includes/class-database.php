<?php
namespace Flavor_QA;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database abstraction layer for all QA tables.
 */
class Database {

    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    // ─── Scans ──────────────────────────────────────────────

    public function create_scan( $scan_types ) {
        $this->wpdb->insert(
            $this->wpdb->prefix . 'flavor_qa_scans',
            [
                'scan_types'  => wp_json_encode( $scan_types ),
                'status'      => 'running',
                'started_at'  => current_time( 'mysql' ),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
        return $this->wpdb->insert_id;
    }

    public function update_scan( $scan_id, $data ) {
        $this->wpdb->update(
            $this->wpdb->prefix . 'flavor_qa_scans',
            $data,
            [ 'id' => $scan_id ],
            null,
            [ '%d' ]
        );
    }

    public function complete_scan( $scan_id ) {
        $issues_count = $this->count_issues_for_scan( $scan_id );
        $this->update_scan( $scan_id, [
            'status'       => 'completed',
            'issues_found' => $issues_count,
            'completed_at' => current_time( 'mysql' ),
        ] );
    }

    public function get_scan( $scan_id ) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wpdb->prefix}flavor_qa_scans WHERE id = %d",
                $scan_id
            )
        );
    }

    public function get_scans( $args = [] ) {
        $defaults = [
            'per_page' => 20,
            'page'     => 1,
            'status'   => '',
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $values = [];

        if ( ! empty( $args['status'] ) ) {
            $where .= ' AND status = %s';
            $values[] = $args['status'];
        }

        $allowed_orderby = [ 'id', 'created_at', 'completed_at', 'issues_found' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $sql = "SELECT * FROM {$this->wpdb->prefix}flavor_qa_scans WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        return $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, $values )
        );
    }

    public function get_latest_scan() {
        return $this->wpdb->get_row(
            "SELECT * FROM {$this->wpdb->prefix}flavor_qa_scans ORDER BY id DESC LIMIT 1"
        );
    }

    // ─── Issues ─────────────────────────────────────────────

    public function insert_issue( $data ) {
        if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
            $data['meta_data'] = wp_json_encode( $data['meta_data'] );
        }
        $data['created_at'] = current_time( 'mysql' );

        $this->wpdb->insert(
            $this->wpdb->prefix . 'flavor_qa_issues',
            $data
        );
        return $this->wpdb->insert_id;
    }

    public function get_issues( $args = [] ) {
        $defaults = [
            'scan_id'      => 0,
            'post_id'      => 0,
            'scanner_type' => '',
            'severity'     => '',
            'is_resolved'  => null,
            'per_page'     => 50,
            'page'         => 1,
            'orderby'      => 'severity',
            'order'        => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $values = [];

        if ( $args['scan_id'] ) {
            $where .= ' AND scan_id = %d';
            $values[] = $args['scan_id'];
        }
        if ( $args['post_id'] ) {
            $where .= ' AND post_id = %d';
            $values[] = $args['post_id'];
        }
        if ( $args['scanner_type'] ) {
            $where .= ' AND scanner_type = %s';
            $values[] = $args['scanner_type'];
        }
        if ( $args['severity'] ) {
            $where .= ' AND severity = %s';
            $values[] = $args['severity'];
        }
        if ( null !== $args['is_resolved'] ) {
            $where .= ' AND is_resolved = %d';
            $values[] = (int) $args['is_resolved'];
        }

        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $sql = "SELECT * FROM {$this->wpdb->prefix}flavor_qa_issues WHERE {$where}";
        $sql .= " ORDER BY FIELD(severity, 'critical', 'error', 'warning', 'info')";
        $sql .= " LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        if ( ! empty( $values ) ) {
            return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ) );
        }
        return $this->wpdb->get_results( $sql );
    }

    public function count_issues_for_scan( $scan_id ) {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}flavor_qa_issues WHERE scan_id = %d",
                $scan_id
            )
        );
    }

    public function get_issue_summary( $scan_id ) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT scanner_type, severity, COUNT(*) as count
                 FROM {$this->wpdb->prefix}flavor_qa_issues
                 WHERE scan_id = %d
                 GROUP BY scanner_type, severity
                 ORDER BY scanner_type, FIELD(severity, 'critical', 'error', 'warning', 'info')",
                $scan_id
            )
        );
    }

    public function resolve_issue( $issue_id ) {
        $this->wpdb->update(
            $this->wpdb->prefix . 'flavor_qa_issues',
            [
                'is_resolved' => 1,
                'resolved_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $issue_id ]
        );
    }

    public function bulk_resolve_issues( $issue_ids ) {
        if ( empty( $issue_ids ) ) {
            return;
        }
        $placeholders = implode( ',', array_fill( 0, count( $issue_ids ), '%d' ) );
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->wpdb->prefix}flavor_qa_issues
                 SET is_resolved = 1, resolved_at = %s
                 WHERE id IN ({$placeholders})",
                array_merge( [ current_time( 'mysql' ) ], $issue_ids )
            )
        );
    }

    // ─── Links ──────────────────────────────────────────────

    public function insert_link( $data ) {
        $data['created_at'] = current_time( 'mysql' );
        $this->wpdb->insert(
            $this->wpdb->prefix . 'flavor_qa_links',
            $data
        );
        return $this->wpdb->insert_id;
    }

    public function get_links( $args = [] ) {
        $defaults = [
            'scan_id'   => 0,
            'post_id'   => 0,
            'is_broken' => null,
            'link_type' => '',
            'per_page'  => 50,
            'page'      => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $values = [];

        if ( $args['scan_id'] ) {
            $where .= ' AND scan_id = %d';
            $values[] = $args['scan_id'];
        }
        if ( $args['post_id'] ) {
            $where .= ' AND post_id = %d';
            $values[] = $args['post_id'];
        }
        if ( null !== $args['is_broken'] ) {
            $where .= ' AND is_broken = %d';
            $values[] = (int) $args['is_broken'];
        }
        if ( $args['link_type'] ) {
            $where .= ' AND link_type = %s';
            $values[] = $args['link_type'];
        }

        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $sql = "SELECT * FROM {$this->wpdb->prefix}flavor_qa_links WHERE {$where} ORDER BY is_broken DESC, created_at DESC LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ) );
    }

    public function count_broken_links( $scan_id ) {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}flavor_qa_links WHERE scan_id = %d AND is_broken = 1",
                $scan_id
            )
        );
    }

    // ─── Screenshots ────────────────────────────────────────

    public function insert_screenshot( $data ) {
        $data['created_at'] = current_time( 'mysql' );
        $this->wpdb->insert(
            $this->wpdb->prefix . 'flavor_qa_screenshots',
            $data
        );
        return $this->wpdb->insert_id;
    }

    public function get_screenshots( $args = [] ) {
        $defaults = [
            'scan_id'  => 0,
            'post_id'  => 0,
            'viewport' => '',
            'per_page' => 50,
            'page'     => 1,
        ];
        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $values = [];

        if ( $args['scan_id'] ) {
            $where .= ' AND scan_id = %d';
            $values[] = $args['scan_id'];
        }
        if ( $args['post_id'] ) {
            $where .= ' AND post_id = %d';
            $values[] = $args['post_id'];
        }
        if ( $args['viewport'] ) {
            $where .= ' AND viewport = %s';
            $values[] = $args['viewport'];
        }

        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $sql = "SELECT * FROM {$this->wpdb->prefix}flavor_qa_screenshots WHERE {$where} ORDER BY post_id ASC, viewport_width ASC LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ) );
    }

    // ─── Cleanup ────────────────────────────────────────────

    public function delete_scan_data( $scan_id ) {
        $tables = [ 'flavor_qa_issues', 'flavor_qa_links', 'flavor_qa_screenshots' ];
        foreach ( $tables as $table ) {
            $this->wpdb->delete(
                $this->wpdb->prefix . $table,
                [ 'scan_id' => $scan_id ],
                [ '%d' ]
            );
        }
        $this->wpdb->delete(
            $this->wpdb->prefix . 'flavor_qa_scans',
            [ 'id' => $scan_id ],
            [ '%d' ]
        );
    }

    public function drop_tables() {
        $tables = [
            'flavor_qa_scans',
            'flavor_qa_issues',
            'flavor_qa_links',
            'flavor_qa_screenshots',
        ];
        foreach ( $tables as $table ) {
            $this->wpdb->query( "DROP TABLE IF EXISTS {$this->wpdb->prefix}{$table}" );
        }
    }
}
