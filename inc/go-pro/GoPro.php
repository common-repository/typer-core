<?php

namespace GoPro;

defined( 'ABSPATH' ) || die();

/**
 * Class GoPro
 * @package GoPro
 */
final class GoPro {

	/**
	 * @var
	 */
	public static $instance;

	/**
	 * @return GoPro
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * GoPro constructor.
	 */
	public function __construct() {
		$this->init();
	}

	public function init() {
		if ( wp_get_theme()->get( 'TextDomain' ) !== 'typer' ) {
			return;
		}

		add_action( 'customize_register', [ $this, 'register_btn' ] );
		add_action( 'customize_controls_enqueue_scripts', function () {
			if ( class_exists( '\TyperPro\TyperPro' ) ) {
				return;
			}

			wp_enqueue_script(
				'go-pro-customize-section-button',
				SVQ_CORE_BASE_URL . '/inc/go-pro/assets/js/customize-controls.js',
				[ 'customize-controls' ],
				SVQ_CORE_VERSION,
				true
			);

			wp_enqueue_style(
				'gp-pro-customize-section-button',
				SVQ_CORE_BASE_URL . '/inc/go-pro/assets/css/customize-controls.css',
				[ 'customize-controls' ],
				SVQ_CORE_VERSION
			);

		} );
	}

	public function register_btn( $customizer ) {
		if ( class_exists( '\TyperPro\TyperPro' ) ) {
			return;
		}

		include_once 'GoProButton.php';

		$customizer->register_section_type( GoProButton::class );

		$customizer->add_section(
			new GoProButton( $customizer, 'typer-go-pro', [
				'title'       => sprintf( esc_html__( 'Unleash %s\'s true potential!', 'typer-core' ), wp_get_theme()->get( 'Name' ) ),
				'button_text' => esc_html__( 'Activate Typer Pro', 'typer-core' ),
				'button_url'  => admin_url( 'themes.php?page=typer-addons' )
			] )
		);
	}

}

GoPro::get_instance();
