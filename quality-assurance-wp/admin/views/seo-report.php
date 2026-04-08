<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$db = new Flavor_QA\Database();
$latest_scan = $db->get_latest_scan();
$scan_id = $latest_scan ? $latest_scan->id : 0;
$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$severity_filter = isset( $_GET['severity'] ) ? sanitize_text_field( $_GET['severity'] ) : '';

$issues = $scan_id ? $db->get_issues( [
    'scan_id'      => $scan_id,
    'scanner_type' => 'seo',
    'severity'     => $severity_filter,
    'is_resolved'  => 0,
    'page'         => $page,
    'per_page'     => 50,
] ) : [];
?>
<div class="wrap flavor-qa-wrap">
    <h1><?php esc_html_e( 'SEO Report', 'quality-assurance-wp' ); ?></h1>

    <?php if ( ! $scan_id ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No scan data available. Run a scan from the Dashboard first.', 'quality-assurance-wp' ); ?></p>
        </div>
    <?php else : ?>

    <!-- Severity Filters -->
    <div class="flavor-qa-filters">
        <ul class="subsubsub">
            <li><a href="<?php echo esc_url( remove_query_arg( 'severity' ) ); ?>" class="<?php echo empty( $severity_filter ) ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'severity', 'critical' ) ); ?>" class="<?php echo 'critical' === $severity_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Critical', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'severity', 'error' ) ); ?>" class="<?php echo 'error' === $severity_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Error', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'severity', 'warning' ) ); ?>" class="<?php echo 'warning' === $severity_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Warning', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'severity', 'info' ) ); ?>" class="<?php echo 'info' === $severity_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Info', 'quality-assurance-wp' ); ?></a></li>
        </ul>
    </div>

    <form id="flavor-qa-seo-form">
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select id="flavor-qa-bulk-action">
                    <option value=""><?php esc_html_e( 'Bulk Actions', 'quality-assurance-wp' ); ?></option>
                    <option value="resolve"><?php esc_html_e( 'Mark as Resolved', 'quality-assurance-wp' ); ?></option>
                    <option value="fix"><?php esc_html_e( 'Auto-Fix (where possible)', 'quality-assurance-wp' ); ?></option>
                </select>
                <button type="button" class="button flavor-qa-apply-bulk" data-scanner="seo">
                    <?php esc_html_e( 'Apply', 'quality-assurance-wp' ); ?>
                </button>
            </div>
        </div>

        <table class="widefat striped flavor-qa-table">
            <thead>
                <tr>
                    <th class="check-column"><input type="checkbox" id="flavor-qa-select-all"></th>
                    <th><?php esc_html_e( 'Severity', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Page', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Issue', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Category', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Current Value', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Suggestion', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'quality-assurance-wp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $issues ) ) : ?>
                    <tr><td colspan="8">
                        <?php echo $severity_filter
                            ? esc_html__( 'No issues match this severity.', 'quality-assurance-wp' )
                            : esc_html__( 'No SEO issues found. Great job!', 'quality-assurance-wp' ); ?>
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $issues as $issue ) : ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="issue_ids[]" value="<?php echo esc_attr( $issue->id ); ?>"
                                   data-fixable="<?php echo esc_attr( $issue->auto_fixable ); ?>">
                        </td>
                        <td>
                            <span class="flavor-qa-severity flavor-qa-severity-<?php echo esc_attr( $issue->severity ); ?>">
                                <?php echo esc_html( ucfirst( $issue->severity ) ); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $issue->post_id, 'raw' ) ); ?>">
                                <?php echo esc_html( get_the_title( $issue->post_id ) ); ?>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $issue->title ); ?></strong>
                            <p class="description"><?php echo esc_html( $issue->description ); ?></p>
                        </td>
                        <td><?php echo esc_html( str_replace( '_', ' ', $issue->category ) ); ?></td>
                        <td class="flavor-qa-value-cell">
                            <?php echo $issue->current_value ? esc_html( mb_strimwidth( $issue->current_value, 0, 60, '...' ) ) : '—'; ?>
                        </td>
                        <td class="flavor-qa-value-cell">
                            <?php echo $issue->suggested_value ? esc_html( mb_strimwidth( $issue->suggested_value, 0, 60, '...' ) ) : '—'; ?>
                        </td>
                        <td>
                            <?php if ( $issue->auto_fixable ) : ?>
                                <button type="button" class="button button-small flavor-qa-fix-issue" data-issue-id="<?php echo esc_attr( $issue->id ); ?>">
                                    <?php esc_html_e( 'Fix', 'quality-assurance-wp' ); ?>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="button button-small flavor-qa-resolve-issue" data-issue-id="<?php echo esc_attr( $issue->id ); ?>">
                                <?php esc_html_e( 'Dismiss', 'quality-assurance-wp' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </form>

    <?php endif; ?>
</div>
