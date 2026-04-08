<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$db = new Flavor_QA\Database();
$latest_scan = $db->get_latest_scan();
$scan_id = $latest_scan ? $latest_scan->id : 0;
$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$category_filter = isset( $_GET['category'] ) ? sanitize_text_field( $_GET['category'] ) : '';

$issues = $scan_id ? $db->get_issues( [
    'scan_id'      => $scan_id,
    'scanner_type' => 'responsive',
    'is_resolved'  => 0,
    'page'         => $page,
    'per_page'     => 50,
] ) : [];

// Group issues by category for stats.
$categories = [];
foreach ( $issues as $issue ) {
    $cat = $issue->category;
    if ( ! isset( $categories[ $cat ] ) ) {
        $categories[ $cat ] = 0;
    }
    $categories[ $cat ]++;
}
?>
<div class="wrap flavor-qa-wrap">
    <h1><?php esc_html_e( 'Responsive Design Report', 'quality-assurance-wp' ); ?></h1>

    <?php if ( ! $scan_id ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No scan data available. Run a scan from the Dashboard first.', 'quality-assurance-wp' ); ?></p>
        </div>
    <?php else : ?>

    <!-- Category Overview -->
    <?php if ( ! empty( $categories ) ) : ?>
    <div class="flavor-qa-overview">
        <?php
        $category_labels = [
            'fixed_width'          => 'Fixed Widths',
            'typography_responsive' => 'Typography',
            'large_spacing'        => 'Large Spacing',
            'negative_margin'      => 'Negative Margins',
            'hidden_all_devices'   => 'Hidden Elements',
            'narrow_column'        => 'Narrow Columns',
            'inline_fixed_width'   => 'Inline Widths',
            'missing_viewport'     => 'Missing Viewport',
            'vw_scrollbar'         => '100vw Issues',
            'small_font'           => 'Small Fonts',
        ];
        foreach ( $categories as $cat => $count ) :
        ?>
        <div class="flavor-qa-stat-card flavor-qa-stat-responsive">
            <div class="flavor-qa-stat-number"><?php echo esc_html( $count ); ?></div>
            <div class="flavor-qa-stat-label"><?php echo esc_html( $category_labels[ $cat ] ?? ucwords( str_replace( '_', ' ', $cat ) ) ); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form id="flavor-qa-responsive-form">
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select id="flavor-qa-bulk-action">
                    <option value=""><?php esc_html_e( 'Bulk Actions', 'quality-assurance-wp' ); ?></option>
                    <option value="resolve"><?php esc_html_e( 'Mark as Resolved', 'quality-assurance-wp' ); ?></option>
                    <option value="fix"><?php esc_html_e( 'Auto-Fix (where possible)', 'quality-assurance-wp' ); ?></option>
                </select>
                <button type="button" class="button flavor-qa-apply-bulk" data-scanner="responsive">
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
                    <th><?php esc_html_e( 'Builder', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Element', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Current', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Suggested', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'quality-assurance-wp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $issues ) ) : ?>
                    <tr><td colspan="10"><?php esc_html_e( 'No responsive issues found. Looking good!', 'quality-assurance-wp' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $issues as $issue ) :
                        $meta = json_decode( $issue->meta_data ?? '{}', true ) ?: [];
                    ?>
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
                                <?php echo esc_html( mb_strimwidth( get_the_title( $issue->post_id ), 0, 30, '...' ) ); ?>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $issue->title ); ?></strong>
                            <p class="description"><?php echo esc_html( $issue->description ); ?></p>
                        </td>
                        <td><?php echo esc_html( $category_labels[ $issue->category ] ?? $issue->category ); ?></td>
                        <td><?php echo esc_html( ucfirst( $meta['builder'] ?? '—' ) ); ?></td>
                        <td>
                            <code><?php echo esc_html( $meta['widget'] ?? $meta['tag'] ?? '—' ); ?></code>
                            <br><small><?php echo esc_html( $meta['element_id'] ?? '' ); ?></small>
                        </td>
                        <td><code><?php echo esc_html( $issue->current_value ?: '—' ); ?></code></td>
                        <td><code><?php echo esc_html( $issue->suggested_value ?: '—' ); ?></code></td>
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
