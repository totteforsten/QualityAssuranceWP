<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$settings = get_option( 'flavor_qa_settings', [] );
?>
<div class="wrap flavor-qa-wrap">
    <h1><?php esc_html_e( 'QA Tool Settings', 'quality-assurance-wp' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'flavor_qa_settings_group' ); ?>

        <!-- General Settings -->
        <div class="flavor-qa-card">
            <h2><?php esc_html_e( 'General', 'quality-assurance-wp' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Scheduled Scan Frequency', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <select name="flavor_qa_settings[scan_frequency]">
                            <option value="daily" <?php selected( $settings['scan_frequency'] ?? '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'quality-assurance-wp' ); ?></option>
                            <option value="weekly" <?php selected( $settings['scan_frequency'] ?? 'weekly', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'quality-assurance-wp' ); ?></option>
                            <option value="disabled" <?php selected( $settings['scan_frequency'] ?? '', 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'quality-assurance-wp' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Max Concurrent Link Checks', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <input type="number" name="flavor_qa_settings[max_concurrent_checks]"
                               value="<?php echo esc_attr( $settings['max_concurrent_checks'] ?? 5 ); ?>"
                               min="1" max="20" class="small-text">
                        <p class="description"><?php esc_html_e( 'Higher values are faster but use more server resources.', 'quality-assurance-wp' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Link Checker Settings -->
        <div class="flavor-qa-card">
            <h2><?php esc_html_e( 'Link Checker', 'quality-assurance-wp' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Link Timeout (seconds)', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <input type="number" name="flavor_qa_settings[link_timeout]"
                               value="<?php echo esc_attr( $settings['link_timeout'] ?? 30 ); ?>"
                               min="5" max="120" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Check External Links', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="flavor_qa_settings[link_check_external]" value="1"
                                <?php checked( $settings['link_check_external'] ?? true ); ?>>
                            <?php esc_html_e( 'Also check links to external websites', 'quality-assurance-wp' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Ignored URL Patterns', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <textarea name="flavor_qa_settings[ignored_urls]" rows="5" class="large-text"
                                  placeholder="https://example.com/*&#10;*.pdf"><?php
                            echo esc_textarea( implode( "\n", $settings['ignored_urls'] ?? [] ) );
                        ?></textarea>
                        <p class="description"><?php esc_html_e( 'One pattern per line. Supports * wildcards.', 'quality-assurance-wp' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- SEO Settings -->
        <div class="flavor-qa-card">
            <h2><?php esc_html_e( 'SEO Audit', 'quality-assurance-wp' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Title Length', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <input type="number" name="flavor_qa_settings[seo_min_title_length]"
                               value="<?php echo esc_attr( $settings['seo_min_title_length'] ?? 30 ); ?>"
                               min="1" max="100" class="small-text">
                        <?php esc_html_e( 'to', 'quality-assurance-wp' ); ?>
                        <input type="number" name="flavor_qa_settings[seo_max_title_length]"
                               value="<?php echo esc_attr( $settings['seo_max_title_length'] ?? 60 ); ?>"
                               min="1" max="200" class="small-text">
                        <?php esc_html_e( 'characters', 'quality-assurance-wp' ); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Description Length', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <input type="number" name="flavor_qa_settings[seo_min_desc_length]"
                               value="<?php echo esc_attr( $settings['seo_min_desc_length'] ?? 120 ); ?>"
                               min="1" max="200" class="small-text">
                        <?php esc_html_e( 'to', 'quality-assurance-wp' ); ?>
                        <input type="number" name="flavor_qa_settings[seo_max_desc_length]"
                               value="<?php echo esc_attr( $settings['seo_max_desc_length'] ?? 160 ); ?>"
                               min="1" max="500" class="small-text">
                        <?php esc_html_e( 'characters', 'quality-assurance-wp' ); ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Screenshot Settings -->
        <div class="flavor-qa-card">
            <h2><?php esc_html_e( 'Screenshots', 'quality-assurance-wp' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Node.js Path', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <input type="text" name="flavor_qa_settings[puppeteer_path]"
                               value="<?php echo esc_attr( $settings['puppeteer_path'] ?? '' ); ?>"
                               class="regular-text" placeholder="/usr/bin/node">
                        <p class="description"><?php esc_html_e( 'Leave empty to auto-detect. Required for local screenshot capture.', 'quality-assurance-wp' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Screenshot API URL (optional)', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <input type="url" name="flavor_qa_settings[screenshot_api_url]"
                               value="<?php echo esc_attr( $settings['screenshot_api_url'] ?? '' ); ?>"
                               class="regular-text" placeholder="https://api.screenshotservice.com/capture">
                        <p class="description"><?php esc_html_e( 'Fallback API for screenshot capture if Puppeteer is unavailable.', 'quality-assurance-wp' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Screenshot API Key', 'quality-assurance-wp' ); ?></th>
                    <td>
                        <input type="text" name="flavor_qa_settings[screenshot_api_key]"
                               value="<?php echo esc_attr( $settings['screenshot_api_key'] ?? '' ); ?>"
                               class="regular-text">
                    </td>
                </tr>
            </table>
        </div>

        <!-- Viewports -->
        <div class="flavor-qa-card">
            <h2><?php esc_html_e( 'Screenshot Viewports', 'quality-assurance-wp' ); ?></h2>
            <div id="flavor-qa-viewports">
                <?php
                $viewports = $settings['screenshot_viewports'] ?? [
                    [ 'name' => 'mobile',  'width' => 375,  'height' => 812 ],
                    [ 'name' => 'tablet',  'width' => 768,  'height' => 1024 ],
                    [ 'name' => 'desktop', 'width' => 1440, 'height' => 900 ],
                ];
                foreach ( $viewports as $i => $vp ) :
                ?>
                <div class="flavor-qa-viewport-row">
                    <input type="text" name="flavor_qa_settings[screenshot_viewports][<?php echo $i; ?>][name]"
                           value="<?php echo esc_attr( $vp['name'] ); ?>" placeholder="Name" style="width:100px">
                    <input type="number" name="flavor_qa_settings[screenshot_viewports][<?php echo $i; ?>][width]"
                           value="<?php echo esc_attr( $vp['width'] ); ?>" placeholder="Width" style="width:80px"> x
                    <input type="number" name="flavor_qa_settings[screenshot_viewports][<?php echo $i; ?>][height]"
                           value="<?php echo esc_attr( $vp['height'] ); ?>" placeholder="Height" style="width:80px">
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>
</div>
