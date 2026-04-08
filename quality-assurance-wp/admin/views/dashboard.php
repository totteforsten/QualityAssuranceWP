<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$db = new Flavor_QA\Database();
$latest_scan = $db->get_latest_scan();
$recent_scans = $db->get_scans( [ 'per_page' => 5 ] );
?>
<div class="wrap flavor-qa-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Quality Assurance Dashboard', 'quality-assurance-wp' ); ?></h1>

    <!-- Scan Controls -->
    <div class="flavor-qa-scan-controls">
        <div class="flavor-qa-card">
            <h2><?php esc_html_e( 'Run New Scan', 'quality-assurance-wp' ); ?></h2>
            <p><?php esc_html_e( 'Select which checks to run and start a new scan.', 'quality-assurance-wp' ); ?></p>
            <div class="flavor-qa-scan-types">
                <label><input type="checkbox" name="scan_types[]" value="links" checked> <?php esc_html_e( 'Dead Links', 'quality-assurance-wp' ); ?></label>
                <label><input type="checkbox" name="scan_types[]" value="seo" checked> <?php esc_html_e( 'SEO Audit', 'quality-assurance-wp' ); ?></label>
                <label><input type="checkbox" name="scan_types[]" value="responsive" checked> <?php esc_html_e( 'Responsive Check', 'quality-assurance-wp' ); ?></label>
                <label><input type="checkbox" name="scan_types[]" value="screenshots"> <?php esc_html_e( 'Screenshots', 'quality-assurance-wp' ); ?></label>
            </div>
            <button id="flavor-qa-start-scan" class="button button-primary button-hero">
                <?php esc_html_e( 'Start Full Scan', 'quality-assurance-wp' ); ?>
            </button>
            <div id="flavor-qa-scan-progress" class="flavor-qa-progress" style="display:none;">
                <div class="flavor-qa-progress-bar">
                    <div class="flavor-qa-progress-fill" style="width:0%"></div>
                </div>
                <span class="flavor-qa-progress-text">0%</span>
                <span class="flavor-qa-progress-status"><?php esc_html_e( 'Initializing...', 'quality-assurance-wp' ); ?></span>
            </div>
            <!-- Live Scan Log -->
            <div id="flavor-qa-scan-log" class="flavor-qa-scan-log" style="display:none;">
                <h3><?php esc_html_e( 'Scan Log', 'quality-assurance-wp' ); ?></h3>
                <div class="flavor-qa-log-entries"></div>
            </div>
        </div>
    </div>

    <?php if ( $latest_scan ) :
        $summary = $db->get_issue_summary( $latest_scan->id );
        $broken_links = $db->count_broken_links( $latest_scan->id );
        $total_issues = $db->count_issues_for_scan( $latest_scan->id );

        // Organize summary by type.
        $by_type = [];
        foreach ( $summary as $row ) {
            $by_type[ $row->scanner_type ][ $row->severity ] = (int) $row->count;
        }
    ?>

    <!-- Overview Cards -->
    <div class="flavor-qa-overview">
        <div class="flavor-qa-stat-card flavor-qa-stat-issues">
            <div class="flavor-qa-stat-number"><?php echo esc_html( $total_issues ); ?></div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'Total Issues', 'quality-assurance-wp' ); ?></div>
        </div>
        <div class="flavor-qa-stat-card flavor-qa-stat-links">
            <div class="flavor-qa-stat-number"><?php echo esc_html( $broken_links ); ?></div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'Broken Links', 'quality-assurance-wp' ); ?></div>
        </div>
        <div class="flavor-qa-stat-card flavor-qa-stat-seo">
            <div class="flavor-qa-stat-number">
                <?php
                $seo_issues = 0;
                if ( isset( $by_type['seo'] ) ) {
                    $seo_issues = array_sum( $by_type['seo'] );
                }
                echo esc_html( $seo_issues );
                ?>
            </div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'SEO Issues', 'quality-assurance-wp' ); ?></div>
        </div>
        <div class="flavor-qa-stat-card flavor-qa-stat-responsive">
            <div class="flavor-qa-stat-number">
                <?php
                $resp_issues = 0;
                if ( isset( $by_type['responsive'] ) ) {
                    $resp_issues = array_sum( $by_type['responsive'] );
                }
                echo esc_html( $resp_issues );
                ?>
            </div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'Responsive Issues', 'quality-assurance-wp' ); ?></div>
        </div>
    </div>

    <!-- Severity Breakdown -->
    <div class="flavor-qa-cards-row">
        <div class="flavor-qa-card flavor-qa-card-half">
            <h3><?php esc_html_e( 'Issues by Severity', 'quality-assurance-wp' ); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Severity', 'quality-assurance-wp' ); ?></th>
                        <th><?php esc_html_e( 'Count', 'quality-assurance-wp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $severity_totals = [ 'critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0 ];
                    foreach ( $summary as $row ) {
                        if ( isset( $severity_totals[ $row->severity ] ) ) {
                            $severity_totals[ $row->severity ] += (int) $row->count;
                        }
                    }
                    foreach ( $severity_totals as $sev => $count ) :
                        if ( $count === 0 ) continue;
                    ?>
                    <tr>
                        <td><span class="flavor-qa-severity flavor-qa-severity-<?php echo esc_attr( $sev ); ?>"><?php echo esc_html( ucfirst( $sev ) ); ?></span></td>
                        <td><?php echo esc_html( $count ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="flavor-qa-card flavor-qa-card-half">
            <h3><?php esc_html_e( 'Issues by Scanner', 'quality-assurance-wp' ); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Scanner', 'quality-assurance-wp' ); ?></th>
                        <th><?php esc_html_e( 'Issues', 'quality-assurance-wp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $scanner_labels = [
                        'links'      => __( 'Dead Links', 'quality-assurance-wp' ),
                        'seo'        => __( 'SEO', 'quality-assurance-wp' ),
                        'responsive' => __( 'Responsive', 'quality-assurance-wp' ),
                        'screenshots' => __( 'Screenshots', 'quality-assurance-wp' ),
                    ];
                    foreach ( $by_type as $type => $severities ) :
                        $total = array_sum( $severities );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $scanner_labels[ $type ] ?? ucfirst( $type ) ); ?></td>
                        <td><?php echo esc_html( $total ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Last Scan Info -->
    <div class="flavor-qa-card">
        <h3><?php esc_html_e( 'Last Scan', 'quality-assurance-wp' ); ?></h3>
        <p>
            <strong><?php esc_html_e( 'Status:', 'quality-assurance-wp' ); ?></strong>
            <span class="flavor-qa-status flavor-qa-status-<?php echo esc_attr( $latest_scan->status ); ?>">
                <?php echo esc_html( ucfirst( $latest_scan->status ) ); ?>
            </span>
        </p>
        <p>
            <strong><?php esc_html_e( 'Started:', 'quality-assurance-wp' ); ?></strong>
            <?php echo esc_html( $latest_scan->started_at ); ?>
        </p>
        <?php if ( $latest_scan->completed_at ) : ?>
        <p>
            <strong><?php esc_html_e( 'Completed:', 'quality-assurance-wp' ); ?></strong>
            <?php echo esc_html( $latest_scan->completed_at ); ?>
        </p>
        <?php endif; ?>
    </div>
    <?php else : ?>
        <div class="flavor-qa-card">
            <p><?php esc_html_e( 'No scans have been run yet. Start your first scan above!', 'quality-assurance-wp' ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Recent Scans -->
    <?php if ( ! empty( $recent_scans ) ) : ?>
    <div class="flavor-qa-card">
        <h3><?php esc_html_e( 'Recent Scans', 'quality-assurance-wp' ); ?></h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Types', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Issues', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'quality-assurance-wp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent_scans as $scan ) :
                    $types = json_decode( $scan->scan_types, true ) ?: [];
                ?>
                <tr>
                    <td>#<?php echo esc_html( $scan->id ); ?></td>
                    <td><?php echo esc_html( implode( ', ', $types ) ); ?></td>
                    <td>
                        <span class="flavor-qa-status flavor-qa-status-<?php echo esc_attr( $scan->status ); ?>">
                            <?php echo esc_html( ucfirst( $scan->status ) ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( $scan->issues_found ); ?></td>
                    <td><?php echo esc_html( $scan->created_at ); ?></td>
                    <td>
                        <button class="button button-small flavor-qa-delete-scan" data-scan-id="<?php echo esc_attr( $scan->id ); ?>">
                            <?php esc_html_e( 'Delete', 'quality-assurance-wp' ); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
