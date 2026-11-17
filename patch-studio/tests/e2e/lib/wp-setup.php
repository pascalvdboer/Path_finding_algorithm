<?php
/**
 * Idempotent WooCommerce store setup for the Patch Studio test site.
 *
 * Run with: wp eval-file tests/e2e/lib/wp-setup.php <checkout:classic|block>
 * (WP-CLI passes extra positional arguments in $args.)
 *
 * Mirrors SPEC §10: Belgium / EUR / 21 % VAT (prices entered excl., shown incl.),
 * flat rate 10,00 + free shipping from 150 (BE + NL), payment by cheque + bank transfer,
 * a page "Patch Studio" with [patch_studio], classic or block cart/checkout.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

$checkout_mode = ( isset( $args[0] ) && 'block' === $args[0] ) ? 'block' : 'classic';

/* ------------------------------------------------------------------ */
/* Store options                                                      */
/* ------------------------------------------------------------------ */
$options = array(
	'blogname'                                   => 'Borduurservice België (test)',
	'blogdescription'                            => 'Lokale testomgeving voor Patch Studio',
	'timezone_string'                            => 'Europe/Brussels',
	'date_format'                                => 'd/m/Y',
	'time_format'                                => 'H:i',
	'start_of_week'                              => '1',
	'woocommerce_store_address'                  => 'Teststraat 1',
	'woocommerce_store_city'                     => 'Kalmthout',
	'woocommerce_store_postcode'                 => '2920',
	'woocommerce_default_country'                => 'BE',
	'woocommerce_allowed_countries'              => 'specific',
	'woocommerce_specific_allowed_countries'     => array( 'BE', 'NL' ),
	'woocommerce_ship_to_countries'              => '',
	'woocommerce_default_customer_address'       => 'base',
	'woocommerce_currency'                       => 'EUR',
	'woocommerce_currency_pos'                   => 'left_space',
	'woocommerce_price_thousand_sep'             => '.',
	'woocommerce_price_decimal_sep'              => ',',
	'woocommerce_price_num_decimals'             => '2',
	'woocommerce_calc_taxes'                     => 'yes',
	'woocommerce_prices_include_tax'             => 'no',
	'woocommerce_tax_based_on'                   => 'shipping',
	'woocommerce_shipping_tax_class'             => 'inherit',
	'woocommerce_tax_round_at_subtotal'          => 'no',
	'woocommerce_tax_display_shop'               => 'incl',
	'woocommerce_tax_display_cart'               => 'incl',
	'woocommerce_tax_total_display'              => 'single',
	'woocommerce_enable_guest_checkout'          => 'yes',
	'woocommerce_enable_checkout_login_reminder' => 'no',
	'woocommerce_enable_signup_and_login_from_checkout' => 'no',
	'woocommerce_enable_coupons'                 => 'no',
	'woocommerce_coming_soon'                    => 'no',
	'woocommerce_store_pages_only'               => 'no',
	'woocommerce_onboarding_profile'             => array( 'skipped' => true ),
	'woocommerce_task_list_hidden'               => 'yes',
	'woocommerce_task_list_complete'             => 'yes',
	'woocommerce_show_marketplace_suggestions'   => 'no',
	'woocommerce_allow_tracking'                 => 'no',
	'woocommerce_analytics_enabled'              => 'no',
	'woocommerce_feature_order_attribution_enabled' => 'no',
	'woocommerce_cart_redirect_after_add'        => 'no',
	'woocommerce_enable_ajax_add_to_cart'        => 'yes',
	'woocommerce_ship_to_destination'            => 'billing',
	'woocommerce_checkout_phone_field'           => 'optional',
	'woocommerce_checkout_company_field'         => 'optional',
	'woocommerce_checkout_address_2_field'       => 'hidden',
	'woocommerce_checkout_highlight_required_fields' => 'yes',
);
foreach ( $options as $key => $value ) {
	update_option( $key, $value );
}

/* ------------------------------------------------------------------ */
/* Tax rates: 21 % standard for BE and NL (also on shipping)          */
/* ------------------------------------------------------------------ */
global $wpdb;
foreach ( array( 'BE', 'NL' ) as $country ) {
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = %s AND tax_rate_class = ''",
			$country
		)
	);
	$rate = array(
		'tax_rate_country'  => $country,
		'tax_rate_state'    => '',
		'tax_rate'          => '21.0000',
		'tax_rate_name'     => 'Btw',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1,
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	);
	if ( $exists ) {
		WC_Tax::_update_tax_rate( (int) $exists, $rate );
	} else {
		WC_Tax::_insert_tax_rate( $rate );
	}
}

/* ------------------------------------------------------------------ */
/* Shipping zone "België en Nederland": flat 10,00 + free from 150    */
/* ------------------------------------------------------------------ */
$zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'België en Nederland' === $z['zone_name'] ) {
		$zone = new WC_Shipping_Zone( (int) $z['id'] );
		break;
	}
}
if ( ! $zone ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'België en Nederland' );
	$zone->set_zone_order( 1 );
	$zone->add_location( 'BE', 'country' );
	$zone->add_location( 'NL', 'country' );
	$zone->save();
}
$have = array();
foreach ( $zone->get_shipping_methods() as $instance_id => $method ) {
	$have[ $method->id ] = (int) $instance_id;
}
if ( empty( $have['flat_rate'] ) ) {
	$have['flat_rate'] = (int) $zone->add_shipping_method( 'flat_rate' );
}
if ( empty( $have['free_shipping'] ) ) {
	$have['free_shipping'] = (int) $zone->add_shipping_method( 'free_shipping' );
}
update_option(
	'woocommerce_flat_rate_' . $have['flat_rate'] . '_settings',
	array(
		'title'      => 'Verzending',
		'tax_status' => 'taxable',
		'cost'       => '10',
	)
);
update_option(
	'woocommerce_free_shipping_' . $have['free_shipping'] . '_settings',
	array(
		'title'            => 'Gratis verzending',
		'requires'         => 'min_amount',
		'min_amount'       => '150',
		'ignore_discounts' => 'no',
	)
);
// Free shipping first: WooCommerce pre-selects the first available method, so a cart ≥ € 150 gets "gratis"
// by default (what Patch Studio promises). Recommended to the owner as well (docs/handoff/woo.md, open point 3).
$wpdb->update( $wpdb->prefix . 'woocommerce_shipping_zone_methods', array( 'method_order' => 1 ), array( 'instance_id' => $have['free_shipping'] ) );
$wpdb->update( $wpdb->prefix . 'woocommerce_shipping_zone_methods', array( 'method_order' => 2 ), array( 'instance_id' => $have['flat_rate'] ) );
WC_Cache_Helper::get_transient_version( 'shipping', true );

/* ------------------------------------------------------------------ */
/* Payment gateways: cheque + bank transfer (bacs)                    */
/* ------------------------------------------------------------------ */
update_option(
	'woocommerce_cheque_settings',
	array(
		'enabled'      => 'yes',
		'title'        => 'Cheque',
		'description'  => 'Testbetaling per cheque.',
		'instructions' => '',
	)
);
update_option(
	'woocommerce_bacs_settings',
	array(
		'enabled'         => 'yes',
		'title'           => 'Overschrijving',
		'description'     => 'Betaal via overschrijving. Je bestelling wordt verwerkt na ontvangst van de betaling.',
		'instructions'    => '',
		'account_details' => '',
	)
);
update_option(
	'woocommerce_bacs_accounts',
	array(
		array(
			'account_name'   => 'Borduurservice België (test)',
			'account_number' => '',
			'bank_name'      => 'Testbank',
			'sort_code'      => '',
			'iban'           => 'BE68539007547034',
			'bic'            => 'GKCCBEBB',
		),
	)
);
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'no' ) );
update_option( 'woocommerce_gateway_order', array( 'bacs' => 0, 'cheque' => 1 ) );

/* ------------------------------------------------------------------ */
/* Pages                                                              */
/* ------------------------------------------------------------------ */
/**
 * Create or update a page by slug and return its ID.
 */
$bps_page = static function ( string $slug, string $title, string $content ): int {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	$data = array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'post_name'      => $slug,
		'post_title'     => $title,
		'post_content'   => $content,
		'comment_status' => 'closed',
	);
	if ( $page ) {
		$data['ID'] = $page->ID;
		wp_update_post( $data );
		return (int) $page->ID;
	}
	return (int) wp_insert_post( $data );
};

if ( 'block' === $checkout_mode ) {
	// No "alignwide": Hello Elementor gives .alignwide margin-inline:-80px without a mobile breakpoint, which pushes the
	// block cart/checkout 80 px off-screen (clipped, not scrollable). The live site uses Elementor's cart widget instead.
	$cart_content     = '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart is-loading"></div><!-- /wp:woocommerce/cart -->';
	$checkout_content = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout wc-block-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->';
} else {
	$cart_content     = '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->';
	$checkout_content = '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->';
}

$ids = array(
	'shop'      => $bps_page( 'winkel', 'Winkel', '' ),
	'cart'      => $bps_page( 'winkelmand', 'Winkelmand', $cart_content ),
	'checkout'  => $bps_page( 'afrekenen', 'Afrekenen', $checkout_content ),
	'myaccount' => $bps_page( 'mijn-account', 'Mijn account', '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->' ),
	'terms'     => $bps_page( 'algemene-voorwaarden', 'Algemene voorwaarden', '<p>Testvoorwaarden.</p>' ),
	'studio'    => $bps_page( 'patch-studio', 'Patch Studio', '<!-- wp:shortcode -->[patch_studio]<!-- /wp:shortcode -->' ),
);
update_option( 'woocommerce_shop_page_id', $ids['shop'] );
update_option( 'woocommerce_cart_page_id', $ids['cart'] );
update_option( 'woocommerce_checkout_page_id', $ids['checkout'] );
update_option( 'woocommerce_myaccount_page_id', $ids['myaccount'] );
update_option( 'woocommerce_terms_page_id', 0 );
// Front page stays the default; the studio lives at /patch-studio/ (like a normal content page).
update_option( 'show_on_front', 'posts' );

/* ------------------------------------------------------------------ */
/* Done: machine-readable summary for start-wp.sh                     */
/* ------------------------------------------------------------------ */
WP_CLI::line(
	wp_json_encode(
		array(
			'checkout'    => $checkout_mode,
			'pages'       => $ids,
			'studio_url'  => get_permalink( $ids['studio'] ),
			'cart_url'    => wc_get_cart_url(),
			'checkout_url' => wc_get_checkout_url(),
			'flat_rate'   => $have['flat_rate'],
			'free'        => $have['free_shipping'],
		)
	)
);
