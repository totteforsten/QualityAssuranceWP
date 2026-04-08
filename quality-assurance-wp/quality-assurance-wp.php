<?php
/**
 * Plugin Name: Quality Assurance WP
 * Plugin URI: https://github.com/totteforsten/qualityassurancewp
 * Description: Comprehensive quality assurance tool for WordPress. Scans for dead links, SEO issues, responsive design problems, and provides visual screenshot verification. Supports Elementor and Breakdance page builders.
 * Version: 1.0.0
 * Author: QualityAssurance Team
 * Author URI: https://github.com/totteforsten
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: quality-assurance-wp
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FLAVOR_QA_VERSION', '1.0.0' );
define( 'FLAVOR_QA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLAVOR_QA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FLAVOR_QA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'FLAVOR_QA_DB_VERSION', '1.0.0' );

// Autoload classes.
spl_autoload_register( function ( $class ) {
    $prefix = 'flavor_QA\\';
    $base_dir = FLAVOR_QA_PLUGIN_DIR . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $relative_class = strtolower( $relative_class );
    $relative_class = str_replace( '_', '-', $relative_class );
    $relative_class = str_replace( '\\', '/', $relative_class );

    // Convert last segment to class file name.
    $parts = explode( '/', $relative_class );
    $last = array_pop( $parts );
    $parts[] = 'class-' . $last;
    $relative_class = implode( '/', $parts );

    $file = $base_dir . $relative_class . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// Load bundled libraries.
require_once FLAVOR_QA_PLUGIN_DIR . 'lib/wp-background-processing/wp-async-request.php';
require_once FLAVOR_QA_PLUGIN_DIR . 'lib/wp-background-processing/wp-background-process.php';

/**
 * Plugin activation.
 */
function flavor_qa_activate() {
    $activator = new Flavor_QA\Activator();
    $activator->activate();
}
register_activation_hook( __FILE__, 'flavor_qa_activate' );

/**
 * Plugin deactivation.
 */
function flavor_qa_deactivate() {
    $activator = new Flavor_QA\Activator();
    $activator->deactivate();
}
register_deactivation_hook( __FILE__, 'flavor_qa_deactivate' );

/**
 * Initialize the plugin.
 */
function flavor_qa_init() {
    $plugin = Flavor_QA\Plugin::get_instance();
    $plugin->init();
}
add_action( 'plugins_loaded', 'flavor_qa_init' );
