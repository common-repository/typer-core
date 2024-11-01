<?php

namespace Seventhqueen\Typer\Core\Theme;

use function Seventhqueen\Typer\typer;

defined( 'ABSPATH' ) || die();

/**
 * Class Compatibility
 * @package Compatibility
 */
final class Compatibility {

	/**
	 * @var
	 */
	public static $instance;

	private $registered_plugins;
	private $api_url = 'https://updates.seventhqueen.com/check/';

	/**
	 * @return Compatibility
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Compatibility constructor.
	 */
	public function __construct() {
		add_filter( 'script_loader_tag', array( $this, 'filter_script_load_tag' ), 10, 2 );
		add_filter( 'typer-required-plugins', [ $this, 'add_more_required_plugins' ] );

		$this->registered_plugins = $this->get_own_plugins();

		if ( ! empty( $this->registered_plugins ) ) {

			foreach ( $this->registered_plugins as $plugin_data ) {

				add_filter( 'in_plugin_update_message-' . $plugin_data['slug'] . '/' . $plugin_data['file'],
					[
						$this,
						'in_plugin_update_message'
					], 10, 2 );
			}
		}

		add_action( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ), 12 );

		add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 12, 3 );

		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 12, 3 );

		add_action( 'init', [ $this, 'clear_plugins_updates' ], 999 );

	}

	/**
	 * Clear plugin transients on license activation
	 */
	public function clear_plugins_updates() {
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'sq_theme_registration' ) {
			set_site_transient( 'update_plugins', null );

		}
	}

	/**
	 * Add message to plugins list
	 *
	 * @param $plugin_data
	 * @param $response
	 */
	public function in_plugin_update_message( $plugin_data, $response ) {

		if ( ! $response->package ) {
			echo sprintf( '&nbsp;<strong><a class="" href="%1$s">%2$s</a><strong>',
				admin_url( 'themes.php?page=typer' ),
				esc_html__( 'Activate Typer license for updates.', 'typer-core' )
			);
		}
	}

	/**
	 * @param $plugin_meta
	 * @param $plugin_file
	 * @param $plugin_data
	 *
	 * @return mixed
	 */
	public function plugin_row_meta( $plugin_meta, $plugin_file, $plugin_data ) {

		if ( ! isset( $plugin_data['slug'] ) ) {
			return $plugin_meta;
		}

		$plugin_details = $this->get_own_plugin_data( $plugin_data['slug'] );

		if ( $plugin_details && empty( $plugin_data['update'] ) ) {

			$plugin_meta['view-details'] = sprintf( '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
				esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_details['slug'] . '&TB_iframe=true&width=600&height=550' ) ),
				esc_attr( sprintf( __( 'More information about %s', 'typer-core' ), $plugin_details['name'] ) ),
				esc_attr( $plugin_details['name'] ),
				'View details'
			);
		}

		return $plugin_meta;
	}

	public function check_update( $data ) {

		foreach ( $this->registered_plugins as $plugin_data ) {

			$plugin_slug = $plugin_data['slug'];

			$new_update_version = $this->check_new_update_version( $plugin_slug );

			if ( $new_update_version ) {

				// Delete plugin api transient data
				if ( $this->get_own_plugin_data( $plugin_slug ) ) {
					delete_site_transient( $this->get_plugin_transient_key( $plugin_slug ) );
				}

				$update = new \stdClass();

				$update->slug        = $plugin_data['slug'];
				$update->plugin      = $plugin_data['file'];
				$update->new_version = $new_update_version;
				$update->url         = false;
				$update->package     = $this->get_package_url( $plugin_data['slug'] );

				$data->response[ $plugin_data['slug'] . '/' . $plugin_data['file'] ] = $update;
			}
		}

		return $data;
	}

	/**
	 * Check if there is a new plugin update available
	 *
	 * @param bool $name
	 *
	 * @return bool
	 */
	public function check_new_update_version( $name = false ) {

		$version = false;

		if ( $data = $this->get_plugin_remote_data( $name ) ) {

			$plugin_data = $this->get_own_plugin_data( $name );

			$plugin_file = WP_PLUGIN_DIR . '/' . $plugin_data['slug'] . '/' . $plugin_data['file'];

			if ( file_exists( $plugin_file ) ) {
				$current_info = get_plugin_data( $plugin_file );

				if ( isset( $current_info['Version'] ) && $current_info['Version']
				     && version_compare( $data->version, $current_info['Version'], '>' ) ) {
					return $data->version;
				}
			}

		}

		return $version;

	}

	/**
	 * Query remote server for plugin data
	 *
	 * @param bool $name
	 *
	 * @return bool|mixed|null
	 */
	private function get_plugin_remote_data( $name = false ) {
		if ( ! $name ) {
			return false;
		}

		if ( ! isset( $_GET['force-check'] ) && $plugin_api_data = get_site_transient( $this->get_plugin_transient_key( $name ) ) ) {
			return $plugin_api_data;
		}

		$purchase_get = wp_remote_get( $this->api_url . '?action=get_metadata&slug=' . $name );

		// Check for error
		if ( ! is_wp_error( $purchase_get ) ) {
			$response = wp_remote_retrieve_body( $purchase_get );

			// Check for error
			if ( ! is_wp_error( $response ) ) {

				$plugin_api_data = json_decode( $response );

				// Set 1 Day transient
				set_site_transient( $this->get_plugin_transient_key( $name ), $plugin_api_data, DAY_IN_SECONDS );

				return $plugin_api_data;
			}
		}


		return false;

	}


	/**
	 * Get Download URL
	 *
	 * @param $name
	 *
	 * @return string
	 */
	private function get_package_url( $name ) {

		if ( ! get_option( 'envato_purchase_code_24818607', '' ) ) {
			return false;
		}

		$api_url = add_query_arg( 'action', 'download', $this->api_url );
		$api_url = add_query_arg( 'slug', $name . '.zip', $api_url );

		return $api_url;
	}

	/**
	 * @param $_data
	 * @param string $_action
	 * @param null $_args
	 *
	 * @return mixed|\stdClass
	 */
	public function plugins_api_filter( $_data, $_action = '', $_args = null ) {

		if ( 'plugin_information' !== $_action ) {
			return $_data;
		}

		if ( ! isset( $_args->slug ) ) {
			return $_data;
		}

		$registered_plugin_data = $this->get_own_plugin_data( $_args->slug );

		if ( ! $registered_plugin_data ) {
			return $_data;
		}

		$plugin_remote_data = $this->get_plugin_remote_data( $_args->slug );

		if ( $plugin_remote_data ) {

			$plugin_api_data = new \stdClass();

			$plugin_api_data->name     = $registered_plugin_data['name'];
			$plugin_api_data->slug     = $registered_plugin_data['slug'];
			$plugin_api_data->author   = $plugin_remote_data->author;
			$plugin_api_data->homepage = isset( $plugin_remote_data->plugin_url ) ? $plugin_remote_data->plugin_url : $plugin_remote_data->homepage;
			$plugin_api_data->requires = $plugin_remote_data->requires;
			$plugin_api_data->tested   = $plugin_remote_data->tested;
			//$plugin_api_data->banners  = $registered_plugin_data['banners'];
			$plugin_api_data->version  = $plugin_remote_data->version;
			$plugin_api_data->sections = array(
				'changelog' => $plugin_remote_data->sections->changelog,
			);

			$_data = $plugin_api_data;
		}

		return $_data;
	}

	private function get_own_plugin_data( $slug ) {

		foreach ( $this->registered_plugins as $plugin ) {
			if ( $slug === $plugin['slug'] ) {
				$plugin['transient_key'] = $this->get_plugin_transient_key( $plugin['slug'] );

				return $plugin;
			}
		}

		return false;
	}

	private function get_plugin_transient_key( $name = '' ) {
		return $name . '_typer_plugin_info_data';
	}


	private function get_own_plugins() {

		$required_plugins = [];

		$required_plugins[] = [
			'name'               => 'Typer Pro Elements',
			'slug'               => 'typer-pro',
			'file'               => 'loader.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Gutenberg blocks, Widgets and other cool stuff.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'Front User Profile',
			'slug'               => 'front-user-profile',
			'file'               => 'loader.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Adds Front-end User profiles.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'SQ Comments Media',
			'slug'               => 'sq-comments-media',
			'file'               => 'loader.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Allow users to upload images in comments.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'SQ Social Share',
			'slug'               => 'sq-social-share',
			'file'               => 'share.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Social sharing for WordPress content.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'Sidebar Generator',
			'slug'               => 'sq-sidebar-generator',
			'file'               => 'loader.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Generates as many sidebars as you need. Then place them on any page you wish.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'Hide Admin Bar',
			'slug'               => 'sq-hide-admin-bar',
			'file'               => 'hide-admin-bar.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Hides the admin bar in front-end area.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'Jet Popup',
			'slug'               => 'jet-popup',
			'file'               => 'jet-popup.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Build awesome popups using Elementor.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'Jet Elements',
			'slug'               => 'jet-elements',
			'file'               => 'jet-elements.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Provides awesome widgets for Elementor builder.',
			'pro'                => true
		];

		$required_plugins[] = [
			'name'               => 'Jet Tabs',
			'slug'               => 'jet-tabs',
			'file'               => 'jet-tabs.php',
			'required'           => false,
			'force_activation'   => false,
			'force_deactivation' => false,
			'external_url'       => '',
			'description'        => 'Tabs and Accordions for Elementor Builder.',
			'pro'                => true
		];

		return $required_plugins;
	}


	public function add_more_required_plugins( $required_plugins ) {

		$required_plugins = array_merge( $required_plugins, $this->registered_plugins );

		$required_plugins[] = [
			'name'               => 'Envato Market - Auto Theme Updates',
			// The plugin name
			'slug'               => 'envato-market',
			// The plugin slug (typically the folder name)
			'source'             => 'https://envato.github.io/wp-envato-market/dist/envato-market.zip',
			// The plugin source
			'required'           => true,
			// If false, the plugin is only 'recommended' instead of required
			'version'            => '2.0.1',
			// E.g. 1.0.0. If set, the active plugin must be this version or higher, otherwise a notice is presented
			'force_activation'   => false,
			// If true, plugin is activated upon theme activation and cannot be deactivated until theme switch
			'force_deactivation' => false,
			// If true, plugin is deactivated upon theme switch, useful for theme-specific plugins
			'external_url'       => '',
			// If set, overrides default API URL and points to an external URL
			'description'        => 'Enables automatic theme updates on your site',
			'pro'                => true
		];

		return $required_plugins;
	}

	/**
	 * Adds async/defer attributes to enqueued / registered scripts.
	 *
	 * If #12009 lands in WordPress, this function can no-op since it would be handled in core.
	 *
	 * @link https://core.trac.wordpress.org/ticket/12009
	 *
	 * @param string $tag The script tag.
	 * @param string $handle The script handle.
	 *
	 * @return string Script HTML string.
	 */
	public function filter_script_load_tag( $tag, $handle ) {
		foreach ( array( 'async', 'defer' ) as $attr ) {
			if ( ! wp_scripts()->get_data( $handle, $attr ) ) {
				continue;
			}

			// Prevent adding attribute when already added in #12009.
			if ( ! preg_match( ":\s$attr(=|>|\s):", $tag ) ) {
				$tag = preg_replace( ':(?=></script>):', " $attr", $tag, 1 );
			}

			// Only allow async or defer, not both.
			break;
		}

		return $tag;
	}

}

Compatibility::get_instance();
