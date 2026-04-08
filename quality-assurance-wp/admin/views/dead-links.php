<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$db = new Flavor_QA\Database();
$latest_scan = $db->get_latest_scan();
$scan_id = $latest_scan ? $latest_scan->id : 0;
$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$filter = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : 'broken';

$args = [
    'scan_id'  => $scan_id,
    'page'     => $page,
    'per_page' => 50,
];

if ( 'broken' === $filter ) {
    $args['is_broken'] = 1;
} elseif ( 'working' === $filter ) {
    $args['is_broken'] = 0;
}

$links = $scan_id ? $db->get_links( $args ) : [];
$broken_count = $scan_id ? $db->count_broken_links( $scan_id ) : 0;
?>
<div class="wrap flavor-qa-wrap">
    <h1><?php esc_html_e( 'Dead Links Report', 'quality-assurance-wp' ); ?></h1>

    <?php if ( ! $scan_id ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No scan data available. Run a scan from the Dashboard first.', 'quality-assurance-wp' ); ?></p>
        </div>
    <?php else : ?>

    <div class="flavor-qa-overview">
        <div class="flavor-qa-stat-card flavor-qa-stat-links">
            <div class="flavor-qa-stat-number"><?php echo esc_html( $broken_count ); ?></div>
            <div class="flavor-qa-stat-label"><?php esc_html_e( 'Broken Links Found', 'quality-assurance-wp' ); ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="flavor-qa-filters">
        <ul class="subsubsub">
            <li><a href="<?php echo esc_url( add_query_arg( 'filter', 'all' ) ); ?>" class="<?php echo 'all' === $filter ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'filter', 'broken' ) ); ?>" class="<?php echo 'broken' === $filter ? 'current' : ''; ?>"><?php esc_html_e( 'Broken', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'filter', 'working' ) ); ?>" class="<?php echo 'working' === $filter ? 'current' : ''; ?>"><?php esc_html_e( 'Working', 'quality-assurance-wp' ); ?></a></li>
        </ul>
    </div>

    <table class="widefat striped flavor-qa-table">
        <thead>
            <tr>
                <th class="check-column"><input type="checkbox" id="flavor-qa-select-all"></th>
                <th><?php esc_html_e( 'URL', 'quality-assurance-wp' ); ?></th>
                <th><?php esc_html_e( 'Status', 'quality-assurance-wp' ); ?></th>
                <th><?php esc_html_e( 'Type', 'quality-assurance-wp' ); ?></th>
                <th><?php esc_html_e( 'Found On', 'quality-assurance-wp' ); ?></th>
                <th><?php esc_html_e( 'Anchor Text', 'quality-assurance-wp' ); ?></th>
                <th><?php esc_html_e( 'Response Time', 'quality-assurance-wp' ); ?></th>
                <th><?php esc_html_e( 'Redirect', 'quality-assurance-wp' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $links ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No links found matching your filter.', 'quality-assurance-wp' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $links as $link ) : ?>
                <tr class="<?php echo $link->is_broken ? 'flavor-qa-row-broken' : ''; ?>">
                    <td><input type="checkbox" name="link_ids[]" value="<?php echo esc_attr( $link->id ); ?>"></td>
                    <td class="flavor-qa-url-cell">
                        <a href="<?php echo esc_url( $link->url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( mb_strimwidth( $link->url, 0, 60, '...' ) ); ?>
                        </a>
                    </td>
                    <td>
                        <span class="flavor-qa-http-status flavor-qa-http-<?php echo esc_attr( $link->is_broken ? 'error' : 'ok' ); ?>">
                            <?php echo esc_html( $link->http_status ?: 'Error' ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( ucfirst( $link->link_type ) ); ?></td>
                    <td>
                        <?php
                        $post_title = get_the_title( $link->post_id );
                        $edit_url = get_edit_post_link( $link->post_id, 'raw' );
                        ?>
                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $post_title ); ?></a>
                    </td>
                    <td><?php echo esc_html( mb_strimwidth( $link->anchor_text, 0, 40, '...' ) ); ?></td>
                    <td><?php echo $link->response_time ? esc_html( sprintf( '%.2fs', $link->response_time ) ) : '—'; ?></td>
                    <td>
                        <?php if ( $link->redirect_url ) : ?>
                            <span title="<?php echo esc_attr( $link->redirect_url ); ?>">
                                <?php echo esc_html( mb_strimwidth( $link->redirect_url, 0, 40, '...' ) ); ?>
                            </span>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>
