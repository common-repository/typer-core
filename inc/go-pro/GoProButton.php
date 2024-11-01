<?php

namespace GoPro;

defined( 'ABSPATH' ) || die();

/**
 * Class GoProButton
 * @package GoPro
 */
class GoProButton extends \WP_Customize_Section {

	/**
	 * @var string
	 */
	public $type = 'typer-go-pro-button';

	/**
	 * @var string
	 */
	public $button_text = '';

	/**
	 * @var string
	 */
	public $button_url = '';

	/**
	 * @var int
	 */
	public $priority = 0;

	/**
	 * @return array
	 */
	public function json() {
		$json       = parent::json();
		$theme      = wp_get_theme();
		$button_url = $this->button_url;

		if ( ! $this->button_url && $theme->get( 'ThemeURI' ) ) {
			$button_url = $theme->get( 'ThemeURI' );
		} elseif ( ! $this->button_url && $theme->get( 'AuthorURI' ) ) {
			$button_url = $theme->get( 'AuthorURI' );
		}

		$json['button_text'] = $this->button_text ?: $theme->get( 'Name' );
		$json['button_url']  = esc_url( $button_url );

		return $json;
	}

	/**
	 * Template
	 */
	protected function render_template() {
		?>
        <li id="accordion-section-{{ data.id }}"
            class="accordion-section control-section typer-go-pro-section cannot-expand">
            <h3 class="accordion-section-title">
                {{ data.title }}
            </h3>
            <# if ( data.button_text && data.button_url ) { #>
            <a href="{{ data.button_url }}" class="button {{ data.type }}" target="_blank"
               rel="external nofollow noopener noreferrer">{{ data.button_text }}</a>
            <# } #>
        </li>
		<?php
	}
}
