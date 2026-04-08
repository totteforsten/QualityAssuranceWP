<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$db = new Flavor_QA\Database();
$latest_scan = $db->get_latest_scan();
$scan_id = $latest_scan ? $latest_scan->id : 0;

// Get all auto-fixable issues.
$fixable_issues = $scan_id ? $db->get_issues( [
    'scan_id'     => $scan_id,
    'is_resolved' => 0,
    'per_page'    => 500,
] ) : [];

// Separate fixable from non-fixable.
$auto_fixable = array_filter( $fixable_issues, function( $i ) { return (bool) $i->auto_fixable; } );
$manual_only  = array_filter( $fixable_issues, function( $i ) { return ! $i->auto_fixable; } );

// Group fixable by category.
$fixable_by_category = [];
foreach ( $auto_fixable as $issue ) {
    $fixable_by_category[ $issue->category ][] = $issue;
}
?>
<div class="wrap flavor-qa-wrap">
    <h1><?php esc_html_e( 'Bulk Actions', 'quality-assurance-wp' ); ?></h1>

    <?php if ( ! $scan_id ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No scan data available. Run a scan from the Dashboard first.', 'quality-assurance-wp' ); ?></p>
        </div>
    <?php else : ?>

    <!-- Summary -->
    <div class="flavor-qa-overview">
        <div class="flavor-qa-stat-card">
            <div class="flavor-qa-stat-number"><?php echo esc_html( count( $fixable_issues ) ); ?></div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'Total Unresolved Issues', 'quality-assurance-wp' ); ?></div>
        </div>
        <div class="flavor-qa-stat-card flavor-qa-stat-fixable">
            <div class="flavor-qa-stat-number"><?php echo esc_html( count( $auto_fixable ) ); ?></div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'Auto-Fixable', 'quality-assurance-wp' ); ?></div>
        </div>
        <div class="flavor-qa-stat-card">
            <div class="flavor-qa-stat-number"><?php echo esc_html( count( $manual_only ) ); ?></div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'Manual Review Required', 'quality-assurance-wp' ); ?></div>
        </div>
    </div>

    <?php if ( ! empty( $auto_fixable ) ) : ?>

    <!-- Fix All Button -->
    <div class="flavor-qa-card">
        <h2><?php esc_html_e( 'Auto-Fix All Issues', 'quality-assurance-wp' ); ?></h2>
        <p><?php esc_html_e( 'Apply all available auto-fixes at once. This will modify page builder data and post content.', 'quality-assurance-wp' ); ?></p>
        <p class="description">
            <?php esc_html_e( 'Warning: Please backup your database before applying bulk fixes. Changes to Elementor/Breakdance data can be complex.', 'quality-assurance-wp' ); ?>
        </p>
        <button id="flavor-qa-fix-all" class="button button-primary button-hero" data-count="<?php echo esc_attr( count( $auto_fixable ) ); ?>">
            <?php printf(
                esc_html__( 'Fix All %d Issues', 'quality-assurance-wp' ),
                count( $auto_fixable )
            ); ?>
        </button>
        <div id="flavor-qa-bulk-progress" class="flavor-qa-progress" style="display:none;">
            <div class="flavor-qa-progress-bar">
                <div class="flavor-qa-progress-fill" style="width:0%"></div>
            </div>
            <span class="flavor-qa-progress-text">0 / <?php echo esc_html( count( $auto_fixable ) ); ?></span>
        </div>
        <div id="flavor-qa-bulk-results" style="display:none;">
            <p class="flavor-qa-bulk-success"></p>
            <p class="flavor-qa-bulk-failed"></p>
        </div>
    </div>

    <!-- Fix by Category -->
    <div class="flavor-qa-card">
        <h2><?php esc_html_e( 'Fix by Category', 'quality-assurance-wp' ); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Category', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Count', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'quality-assurance-wp' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'quality-assurance-wp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $category_descriptions = [
                    'redirect'              => 'Update internal links that redirect to point to the final URL.',
                    'mixed_content'         => 'Replace HTTP URLs with HTTPS on SSL-enabled sites.',
                    'typography_responsive'  => 'Add mobile font-size overrides for large desktop text.',
                    'large_spacing'         => 'Add mobile overrides for large padding/margin values.',
                    'fixed_width'           => 'Add responsive overrides for fixed pixel widths.',
                    'narrow_column'         => 'Set narrow columns to 100% width on mobile.',
                ];

                foreach ( $fixable_by_category as $cat => $cat_issues ) :
                    $ids = array_map( function( $i ) { return $i->id; }, $cat_issues );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $cat ) ) ); ?></strong></td>
                    <td><?php echo esc_html( count( $cat_issues ) ); ?></td>
                    <td><?php echo esc_html( $category_descriptions[ $cat ] ?? '' ); ?></td>
                    <td>
                        <button class="button button-small flavor-qa-fix-category"
                                data-ids="<?php echo esc_attr( wp_json_encode( $ids ) ); ?>">
                            <?php printf( esc_html__( 'Fix %d', 'quality-assurance-wp' ), count( $cat_issues ) ); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else : ?>
        <div class="flavor-qa-card">
            <p><?php esc_html_e( 'No auto-fixable issues found. All remaining issues require manual review.', 'quality-assurance-wp' ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Dismiss All -->
    <div class="flavor-qa-card">
        <h2><?php esc_html_e( 'Dismiss All', 'quality-assurance-wp' ); ?></h2>
        <p><?php esc_html_e( 'Mark all remaining issues from this scan as resolved/dismissed.', 'quality-assurance-wp' ); ?></p>
        <button id="flavor-qa-dismiss-all" class="button" data-count="<?php echo esc_attr( count( $fixable_issues ) ); ?>">
            <?php printf(
                esc_html__( 'Dismiss All %d Issues', 'quality-assurance-wp' ),
                count( $fixable_issues )
            ); ?>
        </button>
    </div>

    <?php endif; ?>
</div>
