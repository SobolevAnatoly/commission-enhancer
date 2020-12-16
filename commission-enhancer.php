<?php
/**
 * Commission Enhancer
 *
 * @package           commission-enhancer
 * @author            Anatolii S.
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Commission Enhancer
 * Plugin URI:        https://codeable.io/
 * Description:       An enhance for WooCommerce Product Vendors: it have made possible for a vendor to get a commission after each part of bill that was paid.
 * Version:           1.0
 * Requires at least: 5.0
 * Requires PHP:      7.2
 * Author:            Anatolii S.
 * Author URI:        https://codeable.io/
 * Text Domain:       commission-enhancer
 * Domain Path:       /languages
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
};

define( 'CE_VERSION', '1.0.0' );

define( 'CE_REQUIRED_WP_VERSION', '5.0' );

define( 'CE_REQUIRED_PHP_VERSION', '7.2' );

define( 'CE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Check for required PHP version
if ( version_compare( PHP_VERSION, CE_REQUIRED_PHP_VERSION, '<' ) ) {
	exit( esc_html( sprintf( 'Commission Enhancer requires PHP 7.2 or higher. You’re still on %s.', PHP_VERSION ) ) );
}

// Check for required Wordpress version
if ( version_compare( get_bloginfo( 'version' ), CE_REQUIRED_WP_VERSION, '<' ) ) {
	exit( esc_html( sprintf( 'Commission Enhancer requires Wordpress 5.0 or higher. You’re still on %s.', get_bloginfo( 'version' ) ) ) );
}

add_action( 'plugins_loaded', function () {
	if ( ! defined( 'WC_VERSION' ) ) {
		exit( esc_html( 'For plugin activation WooCommerce should be installed and activated' ) );
	}

	if ( ! defined( 'WC_PRODUCT_VENDORS_VERSION' ) ) {
		exit( esc_html( 'For plugin activation  WooCommerce Product Vendors extension should be installed and activated' ) );
	}

	if ( ! file_exists( CE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
		exit( esc_html( 'The important plugin file is missing, reinstall plugin' ) );
	}

	require_once CE_PLUGIN_DIR . 'vendor/autoload.php';

	if ( ! class_exists( 'Codeable\\CommissionEnhancer\\Init' ) ) {
		exit( esc_html( 'The important plugin file is missing, reinstall plugin' ) );
	}

	new Codeable\CommissionEnhancer\Init();
} );
