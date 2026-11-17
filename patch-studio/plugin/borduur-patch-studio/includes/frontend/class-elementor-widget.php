<?php
/**
 * Elementor widget "Patch Studio" (bps-patch-studio). Renders exactly the same markup as [patch_studio].
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Frontend;

use BorduurPatchStudio\Woo\Config;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Widget. No colour/style controls on purpose: the tool inherits the site's Elementor global colours
 * and fonts through CSS variables, so it always stays in the theme (and stays simple to set up).
 */
class Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 */
	public function get_name() {
		return 'bps-patch-studio';
	}

	/**
	 * Title.
	 */
	public function get_title() {
		return esc_html__( 'Patch Studio', 'borduur-patch-studio' );
	}

	/**
	 * Icon.
	 */
	public function get_icon() {
		return 'eicon-image-box';
	}

	/**
	 * Categories.
	 */
	public function get_categories() {
		return array( 'woocommerce-elements', 'general' );
	}

	/**
	 * Keywords.
	 */
	public function get_keywords() {
		return array( 'patch', 'patches', 'borduur', 'badge', 'ontwerp', 'designer', 'écusson' );
	}

	/**
	 * Scripts (only on pages that contain the widget).
	 */
	public function get_script_depends() {
		return array( 'bps-app' );
	}

	/**
	 * Styles.
	 */
	public function get_style_depends() {
		return array( 'bps-style' );
	}

	/**
	 * Lighter DOM (Elementor optimized markup).
	 */
	public function has_widget_inner_wrapper(): bool {
		return false;
	}

	/**
	 * The tool is interactive: never cache its output in Elementor's element cache.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'bps_content',
			array( 'label' => esc_html__( 'Startinstellingen', 'borduur-patch-studio' ) )
		);

		$techniques = array( '' => esc_html__( 'Automatisch (ons advies)', 'borduur-patch-studio' ) );
		foreach ( Config::enabled_techniques() as $id ) {
			$techniques[ $id ] = Config::technique_label( $id, 'nl' );
		}
		$this->add_control(
			'techniek',
			array(
				'label'       => esc_html__( 'Standaardtechniek', 'borduur-patch-studio' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => $techniques,
				'description' => esc_html__( 'Laat op "Automatisch" staan: de tool kiest dan de techniek die het best bij het ontwerp past.', 'borduur-patch-studio' ),
			)
		);

		$this->add_control(
			'maat',
			array(
				'label'       => esc_html__( 'Startmaat (cm)', 'borduur-patch-studio' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => (float) Config::path( 'sizes.minCm', 4 ),
				'max'         => (float) Config::path( 'sizes.maxQuoteCm', 30 ),
				'step'        => 0.5,
				'default'     => '',
				'description' => esc_html__( 'Leeg = maatadvies van de tool.', 'borduur-patch-studio' ),
			)
		);

		$this->add_control(
			'aantal',
			array(
				'label'   => esc_html__( 'Startaantal', 'borduur-patch-studio' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'step'    => 1,
				'default' => '',
			)
		);

		$shapes = array( 'auto' => esc_html__( 'Automatisch (uit het ontwerp)', 'borduur-patch-studio' ) );
		foreach ( (array) Config::path( 'shapeOrder', array_keys( (array) Config::path( 'shapes', array() ) ) ) as $id ) {
			$shapes[ (string) $id ] = Config::shape_label( (string) $id, 'nl' );
		}
		$this->add_control(
			'vorm',
			array(
				'label'   => esc_html__( 'Startvorm', 'borduur-patch-studio' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'auto',
				'options' => $shapes,
			)
		);

		$this->add_control(
			'compact',
			array(
				'label'        => esc_html__( 'Compacte weergave', 'borduur-patch-studio' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render through the shared function.
	 */
	protected function render() {
		$s = $this->get_settings_for_display();
		echo Shortcode::render( // phpcs:ignore WordPress.Security.EscapeOutput -- render() escapes everything itself.
			array(
				'techniek' => (string) ( $s['techniek'] ?? '' ),
				'maat'     => (string) ( $s['maat'] ?? '' ),
				'aantal'   => (string) ( $s['aantal'] ?? '' ),
				'vorm'     => (string) ( $s['vorm'] ?? 'auto' ),
				'compact'  => 'yes' === ( $s['compact'] ?? '' ) ? 'yes' : 'no',
			)
		);
	}
}
