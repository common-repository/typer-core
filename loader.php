<?php
/**
 * Plugin Name: Typer Core
 * Plugin URI:  https://seventhqueen.com/
 * Description: Enables customizer options, importing and others features for Typer Theme
 * Author:      SeventhQueen
 * Author URI: https://seventhqueen.com/?utm_source=wp-plugins&utm_campaign=author-uri&utm_medium=wp-dash
 * Version:     1.9.6
 * Text Domain: typer-core
 * Domain Path: /languages
 * License:     GPLv3
 */

define( 'SVQ_CORE_VERSION', '1.9.6' );
define( 'SVQ_CORE_FILE', __FILE__ );
define( 'SVQ_CORE_BASE_URL', plugins_url( '/', SVQ_CORE_FILE ) );
define( 'SVQ_CORE_BASE_PATH', plugin_dir_path( SVQ_CORE_FILE ) );

require_once SVQ_CORE_BASE_PATH . '/inc/sq-import/import.php';
require_once SVQ_CORE_BASE_PATH . '/inc/customizr/kirki.php';
require_once SVQ_CORE_BASE_PATH . '/inc/seventor/plugin.php';
require_once SVQ_CORE_BASE_PATH . '/inc/go-pro/GoPro.php';
require_once SVQ_CORE_BASE_PATH . '/inc/theme/Compatibility.php';
require_once SVQ_CORE_BASE_PATH . '/inc/panel/Importer.php';

/*
 * Localization
 */
function typer_core_load_plugin_textdomain() {
	load_plugin_textdomain( 'typer-core', false, basename( __DIR__ ) . '/languages/' );
}
add_action( 'plugins_loaded', 'typer_core_load_plugin_textdomain' );

if ( ! function_exists( 'typer_core_load_carbon' ) ) {
	/**
	 * Load Carbon Fields framework
	 */
	function typer_core_load_carbon() {

		// Don't load on Elementor edit page
		if ( isset( $_GET['post'], $_GET['action'] ) && $_GET['action'] === 'elementor' && is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Don't load on customizer
		if ( is_customize_preview() ) {
			return;
		}

		if ( ! class_exists( '\Carbon_Fields\Container' ) ) {
			if ( file_exists( SVQ_CORE_BASE_PATH . '/inc/carbon-fields/vendor/autoload.php' ) ) {

				define( 'Carbon_Fields_Plugin\PLUGIN_FILE', __FILE__ );

				define( 'Carbon_Fields_Plugin\RELATIVE_PLUGIN_FILE', basename( dirname( \Carbon_Fields_Plugin\PLUGIN_FILE ) ) . '/' . basename( \Carbon_Fields_Plugin\PLUGIN_FILE ) );

				include_once SVQ_CORE_BASE_PATH . '/inc/carbon-fields/vendor/autoload.php';

				\Carbon_Fields\Carbon_Fields::boot();
			}
		}
	}
}
add_action( 'after_setup_theme', 'typer_core_load_carbon', 12 );
