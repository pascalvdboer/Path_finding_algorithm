<?php
/**
 * PHP pricing engine against tests/pricing/vectors.json (same file as the Node test). Exit code 1 on any mismatch.
 * Run: php tests/pricing/run-php.php   (npm run test:php)
 *
 * @package BorduurPatchStudio
 */

require __DIR__ . '/bootstrap.php';

use BorduurPatchStudio\Pricing;

/**
 * Value for an expect key (aliases from SPEC §7.5, else dotted path).
 *
 * @param array  $q   Quote.
 * @param string $key Key.
 * @return mixed
 */
function bps_pick( array $q, string $key ) {
	if ( 'errors' === $key ) {
		return array_map( static fn( $e ) => $e['code'], $q['errors'] );
	}
	if ( 'shippingIncl' === $key ) {
		return $q['shipping']['incl'];
	}
	if ( 'tierDiscount' === $key ) {
		return $q['tier']['discount'];
	}
	$v = $q;
	foreach ( explode( '.', $key ) as $part ) {
		if ( ! is_array( $v ) || ! array_key_exists( $part, $v ) ) {
			return null;
		}
		$v = $v[ $part ];
	}
	return $v;
}

/**
 * Strict comparison after a JSON round trip (so 45.0 === 45, like JS numbers).
 *
 * @param mixed $a Actual.
 * @param mixed $b Expected.
 */
function bps_same( $a, $b ): bool {
	$norm = static function ( $v ) use ( &$norm ) {
		if ( is_int( $v ) ) {
			return (float) $v;
		}
		if ( is_array( $v ) ) {
			return array_map( $norm, $v );
		}
		return $v;
	};
	return $norm( $a ) === $norm( $b );
}

$vectors = json_decode( (string) file_get_contents( __DIR__ . '/vectors.json' ), true, 512, JSON_THROW_ON_ERROR );
$fail    = 0;
$checks  = 0;
foreach ( $vectors as $v ) {
	$q   = Pricing::quote( $v['input'], bps_test_config( $v['configOverrides'] ?? array() ) );
	$bad = array();
	foreach ( $v['expect'] as $key => $expected ) {
		++$checks;
		$actual = bps_pick( $q, (string) $key );
		if ( ! bps_same( $actual, $expected ) ) {
			$bad[] = sprintf( '    %s: expected %s, got %s', $key, json_encode( $expected, JSON_UNESCAPED_UNICODE ), json_encode( $actual, JSON_UNESCAPED_UNICODE ) );
		}
	}
	if ( $bad ) {
		++$fail;
		echo 'FAIL ' . $v['name'] . "\n" . implode( "\n", $bad ) . "\n";
	} else {
		echo 'ok   ' . $v['name'] . "\n";
	}
}

// Extra PHP-only API checks.
$cfg   = bps_test_defaults();
$extra = array(
	'round_half_up(230.175)'              => array( Pricing::round_half_up( 230.175 ), 230.18 ),
	'round_half_up(-1.005)'               => array( Pricing::round_half_up( -1.005 ), -1.01 ),
	'round_half_up(1.00005, 4)'           => array( Pricing::round_half_up( 1.00005, 4 ), 1.0001 ),
	'tier_for(24)'                        => array( Pricing::tier_for( 24, $cfg ), array( 'min' => 10, 'discount' => 0.05 ) ),
	'tier_for(0)'                         => array( Pricing::tier_for( 0, $cfg ), array( 'min' => 1, 'discount' => 0.0 ) ),
	'cart_unit_excl(emb 10cm x25)'        => array( Pricing::cart_unit_excl( array( 'technique' => 'embroidered', 'size_cm' => 10.0, 'options' => array( 'on_film', 'iron_on', 'thickness_normal' ) ), 25, 0, $cfg ), 9.207 ),
	'cart_unit_excl(pool 15)'             => array( Pricing::cart_unit_excl( array( 'technique' => 'embroidered', 'size_cm' => '8', 'options' => array( 'cut_from_fabric', 'sew_on', 'thickness_normal' ) ), 10, 15, $cfg ), 4.68 ),
	'cart_unit_excl(sublimated no pool)'  => array( Pricing::cart_unit_excl( array( 'technique' => 'sublimated', 'size_cm' => 4, 'options' => array( 'embroidered_border', 'sew_on', 'thickness_normal', 'carrier_standard' ) ), 100, 50, $cfg ), 2.5 ),
	'default_options(sublimated)'         => array( Pricing::default_options( $cfg, 'sublimated' ), array( 'embroidered_border', 'sew_on', 'thickness_normal', 'carrier_standard' ) ),
	'quote_all pvc qty normalised'        => array( Pricing::quote_all( array( 'sizeCm' => 8, 'qty' => 10 ), $cfg )['pvc']['qty'], 50 ),
	'params encode as JSON object'        => array( json_encode( Pricing::quote( array( 'technique' => 'woven', 'sizeCm' => 8, 'qty' => 1, 'options' => array() ), $cfg )['errors'][0]['params'] ), '{"technique":"woven"}' ),
);
foreach ( $extra as $name => list( $actual, $expected ) ) {
	++$checks;
	if ( bps_same( $actual, $expected ) ) {
		echo "ok   $name\n";
	} else {
		++$fail;
		echo "FAIL $name: expected " . json_encode( $expected ) . ', got ' . json_encode( $actual ) . "\n";
	}
}

printf( "\n%d vectors, %d checks, %d failed\n", count( $vectors ), $checks, $fail );
exit( $fail ? 1 : 0 );
