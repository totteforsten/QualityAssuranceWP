<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$db = new Flavor_QA\Database();
$latest_scan = $db->get_latest_scan();
$scan_id = $latest_scan ? $latest_scan->id : 0;
$viewport_filter = isset( $_GET['viewport'] ) ? sanitize_text_field( $_GET['viewport'] ) : '';
$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

$screenshots = $scan_id ? $db->get_screenshots( [
    'scan_id'  => $scan_id,
    'viewport' => $viewport_filter,
    'page'     => $page,
] ) : [];

// Group screenshots by post.
$by_post = [];
foreach ( $screenshots as $ss ) {
    $by_post[ $ss->post_id ][] = $ss;
}
?>
<div class="wrap flavor-qa-wrap">
    <h1><?php esc_html_e( 'Screenshots', 'quality-assurance-wp' ); ?></h1>

    <?php if ( ! $scan_id ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No scan data available. Run a scan with Screenshots enabled from the Dashboard.', 'quality-assurance-wp' ); ?></p>
        </div>
    <?php elseif ( empty( $screenshots ) ) :
        $plugin_dir = FLAVOR_QA_PLUGIN_DIR;
        $has_puppeteer = is_dir( FLAVOR_QA_PLUGIN_DIR . 'node_modules/puppeteer' );
        $has_node = false;
        $node_paths = [ '/usr/bin/node', '/usr/local/bin/node' ];
        foreach ( $node_paths as $np ) {
            if ( file_exists( $np ) ) { $has_node = true; break; }
        }
        if ( ! $has_node ) {
            exec( 'which node 2>/dev/null', $node_output );
            $has_node = ! empty( $node_output );
        }
    ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e( 'No screenshots found. Make sure Screenshots is enabled when running a scan.', 'quality-assurance-wp' ); ?></p>

            <h4><?php esc_html_e( 'Setup Status:', 'quality-assurance-wp' ); ?></h4>
            <ul style="list-style:disc;margin-left:20px;">
                <li>
                    <?php if ( $has_node ) : ?>
                        <strong style="color:#00a32a;">&#10003;</strong> <?php esc_html_e( 'Node.js found', 'quality-assurance-wp' ); ?>
                    <?php else : ?>
                        <strong style="color:#d63638;">&#10007;</strong> <?php esc_html_e( 'Node.js not found. Install Node.js 18+ on your server.', 'quality-assurance-wp' ); ?>
                    <?php endif; ?>
                </li>
                <li>
                    <?php if ( $has_puppeteer ) : ?>
                        <strong style="color:#00a32a;">&#10003;</strong> <?php esc_html_e( 'Puppeteer installed', 'quality-assurance-wp' ); ?>
                    <?php else : ?>
                        <strong style="color:#d63638;">&#10007;</strong> <?php esc_html_e( 'Puppeteer not installed.', 'quality-assurance-wp' ); ?>
                    <?php endif; ?>
                </li>
            </ul>

            <?php if ( ! $has_puppeteer ) : ?>
                <p><?php esc_html_e( 'To install Puppeteer, run this command on your server:', 'quality-assurance-wp' ); ?></p>
                <p><code>cd <?php echo esc_html( $plugin_dir ); ?> && npm install</code></p>
                <?php if ( $has_node ) : ?>
                    <button id="flavor-qa-install-puppeteer" class="button button-primary">
                        <?php esc_html_e( 'Install Puppeteer Now', 'quality-assurance-wp' ); ?>
                    </button>
                    <span id="flavor-qa-install-status" style="margin-left:10px;"></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php else : ?>

    <!-- Viewport Filters -->
    <div class="flavor-qa-filters">
        <ul class="subsubsub">
            <li><a href="<?php echo esc_url( remove_query_arg( 'viewport' ) ); ?>" class="<?php echo empty( $viewport_filter ) ? 'current' : ''; ?>"><?php esc_html_e( 'All Viewports', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'viewport', 'mobile' ) ); ?>" class="<?php echo 'mobile' === $viewport_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Mobile', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'viewport', 'tablet' ) ); ?>" class="<?php echo 'tablet' === $viewport_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Tablet', 'quality-assurance-wp' ); ?></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'viewport', 'desktop' ) ); ?>" class="<?php echo 'desktop' === $viewport_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Desktop', 'quality-assurance-wp' ); ?></a></li>
        </ul>
    </div>

    <div class="flavor-qa-screenshots-grid">
        <?php foreach ( $by_post as $post_id => $post_screenshots ) : ?>
        <div class="flavor-qa-screenshot-group">
            <h3>
                <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank">
                    <?php echo esc_html( get_the_title( $post_id ) ); ?>
                </a>
                <a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>" class="flavor-qa-edit-link">(<?php esc_html_e( 'edit', 'quality-assurance-wp' ); ?>)</a>
            </h3>
            <div class="flavor-qa-screenshot-row">
                <?php foreach ( $post_screenshots as $ss ) : ?>
                <div class="flavor-qa-screenshot-item">
                    <div class="flavor-qa-screenshot-label">
                        <?php echo esc_html( ucfirst( $ss->viewport ) ); ?>
                        (<?php echo esc_html( $ss->viewport_width . 'x' . $ss->viewport_height ); ?>)
                    </div>
                    <a href="<?php echo esc_url( $ss->file_url ); ?>" target="_blank" class="flavor-qa-screenshot-link">
                        <img src="<?php echo esc_url( $ss->file_url ); ?>"
                             alt="<?php echo esc_attr( get_the_title( $post_id ) . ' - ' . $ss->viewport ); ?>"
                             loading="lazy"
                             class="flavor-qa-screenshot-img">
                    </a>
                    <div class="flavor-qa-screenshot-meta">
                        <?php echo esc_html( size_format( $ss->file_size ) ); ?>
                        &middot; <?php echo esc_html( $ss->created_at ); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>
