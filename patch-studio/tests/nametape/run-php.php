<?php
/**
 * Name tapes: PHP twins against the shared vectors (fit-vectors.json, price-vectors.json) + Validator checks.
 * Run: php tests/nametape/run-php.php   (exit code 1 on any mismatch)
 *
 * @package BorduurPatchStudio
 */

define( 'BPS_TESTING', true );
$plugin = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio';
require_once $plugin . '/includes/nametape/class-fit.php';
require_once $plugin . '/includes/nametape/class-pricing.php';
require_once $plugin . '/includes/nametape/class-validator.php';

use BorduurPatchStudio\Nametape\Fit;
use BorduurPatchStudio\Nametape\Pricing;
use BorduurPatchStudio\Nametape\Validator;

/**
 * JSON file as array.
 *
 * @param string $p Path.
 */
function nt_json( string $p ): array {
	return json_decode( (string) file_get_contents( $p ), true, 512, JSON_THROW_ON_ERROR );
}

/**
 * Deep merge like Settings::deep_merge (objects recursive, lists/scalars replaced).
 *
 * @param mixed $base Base.
 * @param mixed $over Override.
 * @return mixed
 */
function nt_merge( $base, $over ) {
	$is_obj = static fn( $v ) => is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );
	if ( ! $is_obj( $base ) || ! $is_obj( $over ) ) {
		return $over;
	}
	foreach ( $over as $k => $v ) {
		$base[ $k ] = ( isset( $base[ $k ] ) && $is_obj( $base[ $k ] ) && $is_obj( $v ) ) ? nt_merge( $base[ $k ], $v ) : $v;
	}
	return $base;
}

/**
 * Normalise for a JS-like comparison: ints → floats, recursively.
 *
 * @param mixed $v Value.
 * @return mixed
 */
function nt_norm( $v ) {
	if ( is_int( $v ) ) {
		return (float) $v;
	}
	if ( is_array( $v ) ) {
		return array_map( 'nt_norm', $v );
	}
	return $v;
}

$cfg      = nt_json( $plugin . '/config/nametape.json' );
$metrics  = nt_json( $plugin . '/config/nametape-metrics.json' );
$defaults = nt_json( $plugin . '/config/defaults.json' );
$fail     = 0;
$pass     = 0;

$check = static function ( bool $ok, string $name, $actual = null, $expected = null ) use ( &$fail, &$pass ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	fwrite( STDERR, "FAIL: $name\n" );
	if ( null !== $actual || null !== $expected ) {
		fwrite( STDERR, '  actual:   ' . json_encode( $actual, JSON_UNESCAPED_UNICODE ) . "\n" );
		fwrite( STDERR, '  expected: ' . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . "\n" );
	}
};

// 1. Fit vectors.
$fv = nt_json( __DIR__ . '/fit-vectors.json' );
foreach ( $fv['fit'] as $c ) {
	$got = Fit::fit_text( $c['input'], $cfg, $metrics );
	$check( nt_norm( $got ) === nt_norm( $c['expect'] ), 'fit: ' . $c['name'], $got, $c['expect'] );
}
foreach ( $fv['rows'] as $c ) {
	$conf = $c['cfg'] ? nt_merge( $cfg, $c['cfg'] ) : $cfg;
	$got  = array();
	foreach ( $c['batch']['rows'] as $r ) {
		$got[] = Fit::check_row( $r, $c['batch'], $conf, $metrics );
	}
	$check( nt_norm( $got ) === nt_norm( $c['expect'] ), 'row: ' . $c['name'], $got, $c['expect'] );
}

// 2. Price vectors.
$pv   = nt_json( __DIR__ . '/price-vectors.json' );
$base = array(
	'patchPoolQty'        => 0,
	'otherNametapePieces' => 0,
	'otherItemsInclVat'   => 0,
	'vatRate'             => $defaults['vat']['rate'],
	'tiers'               => $defaults['tiers'],
	'shipping'            => $defaults['shipping'],
	'locale'              => 'nl-BE',
);
foreach ( $pv['cases'] as $c ) {
	$conf = $c['cfg'] ? nt_merge( $cfg, $c['cfg'] ) : $cfg;
	$ctx  = array_merge( $base, (array) $c['ctx'] );
	$got  = Pricing::quote( $c['batch'], $ctx, $conf );
	$check( nt_norm( $got ) === nt_norm( $c['expect'] ), 'price: ' . $c['name'], $got, $c['expect'] );
}

// 3. Validator.
$batch = static function ( array $over = array() ): array {
	return array_merge(
		array(
			'v'         => 1,
			'locale'    => 'nl-BE',
			'format'    => '10x2.5',
			'fabric'    => 'black',
			'letter'    => 'white',
			'border'    => 'satin_letter',
			'font'      => 'block',
			'uppercase' => true,
			'backing'   => 'hook',
			'loop'      => false,
			'bloodMode' => null,
			'rows'      => array(
				array(
					'name'  => 'Peeters J.',
					'line2' => '',
					'blood' => null,
					'qty'   => 1,
				),
			),
		),
		$over
	);
};
$codes = static fn( array $r ) => array_map( static fn( $e ) => $e['code'], $r['errors'] );

$r = Validator::validate( $batch(), $cfg, $metrics );
$check( $r['ok'] && 'ok' === $r['rows'][0]['fit']['status'], 'validator: valid batch' );

$r = Validator::validate( $batch( array( 'fabric' => 'camo' ) ), $cfg, $metrics );
$check( in_array( 'bad_fabric', $codes( $r ), true ), 'validator: out-of-stock fabric refused', $codes( $r ) );

$r = Validator::validate( $batch( array( 'font' => '<script>' , 'format' => 'xx', 'backing' => 'magic' ) ), $cfg, $metrics );
$check( array( 'bad_format', 'bad_font', 'bad_backing' ) === $codes( $r ), 'validator: enums whitelisted', $codes( $r ) );

$r = Validator::validate( $batch( array( 'rows' => array( array( 'name' => 'VANDENBROUCKE-DESMEDT ALEX.' ) ) ) ), $cfg, $metrics );
$check( ! $r['ok'] && array( 'row_errors' ) === $codes( $r ) && array( 'too_long' ) === $r['badRows'][0]['codes'], 'validator: too long row → row_errors', $r['badRows'] );

$r = Validator::validate( $batch( array( 'rows' => array( array( 'name' => 'VANDENBROUCKE-DESMEDT ALEX.' ) ) ) ), $cfg, $metrics, false );
$check( $r['ok'] && 1 === count( $r['badRows'] ), 'validator: quote mode tolerates row errors' );

$r = Validator::validate( $batch( array( 'rows' => array( array( 'name' => '<b>Maes</b> L.', 'qty' => 500 ), array( 'name' => 'X', 'qty' => -3 ) ) ) ), $cfg, $metrics );
$check( 'Maes L.' === $r['batch']['rows'][0]['name'] && 99 === $r['batch']['rows'][0]['qty'] && 1 === $r['batch']['rows'][1]['qty'], 'validator: tags stripped, qty clamped', $r['batch']['rows'] );

$r = Validator::validate( $batch( array( 'rows' => array_fill( 0, 260, array( 'name' => 'A' ) ) ) ), $cfg, $metrics );
$check( in_array( 'too_many_rows', $codes( $r ), true ) && 250 === count( $r['batch']['rows'] ), 'validator: max 250 rows' );

$r = Validator::validate( $batch( array( 'rows' => array_fill( 0, 25, array( 'name' => 'A', 'qty' => 99 ) ) ) ), $cfg, $metrics );
$check( in_array( 'too_many_pieces', $codes( $r ), true ), 'validator: total pieces ≤ 2000', $codes( $r ) );

$r = Validator::validate( $batch( array( 'rows' => array( array( 'name' => 'Peeters J.', 'blood' => 'O pos' ) ) ) ), $cfg, $metrics );
$check( 'separate' === $r['batch']['bloodMode'] && array( 'group' => 'O', 'rh' => '+' ) === $r['batch']['rows'][0]['blood'], 'validator: blood string parsed, auto mode = separate on 1-line format', $r['batch'] );

$r = Validator::validate( $batch( array( 'format' => '12x3', 'rows' => array( array( 'name' => 'Peeters J.', 'blood' => array( 'group' => '0', 'rh' => '-' ) ) ) ) ), $cfg, $metrics );
$check( 'line2' === $r['batch']['bloodMode'], 'validator: auto mode = line2 on 2-line format', $r['batch']['bloodMode'] );

$r = Validator::validate( $batch( array( 'bloodMode' => 'line2', 'rows' => array( array( 'name' => 'P', 'blood' => 'A+' ) ) ) ), $cfg, $metrics );
$check( in_array( 'bad_blood_mode', $codes( $r ), true ), 'validator: line2 blood on 1-line format refused', $codes( $r ) );

$r = Validator::validate( $batch( array( 'loop' => true, 'backing' => 'sew' ) ), $cfg, $metrics );
$check( false === $r['batch']['loop'], 'validator: loop only with hook' );

$r = Validator::validate( $batch( array( 'format' => 'custom', 'custom' => array( 'w' => 140, 'h' => 30 ) ) ), $cfg, $metrics );
$check( $r['ok'] && array( 'w' => 140, 'h' => 30 ) === $r['batch']['custom'], 'validator: custom size ok' );

$r = Validator::validate( $batch( array( 'format' => 'custom', 'custom' => array( 'w' => 40, 'h' => 30 ) ) ), $cfg, $metrics );
$check( in_array( 'size_out_of_range', $codes( $r ), true ), 'validator: custom size out of range' );

$r = Validator::validate( $batch( array( 'rows' => array( array( 'name' => 'JANSSENS K.', 'line2' => 'Lokale Politie' ) ), 'format' => '12x3' ) ), $cfg, $metrics );
$check( array( 'POLITIE', 'LOKALE POLITIE' ) === $r['review'] && array( 0 ) === $r['reviewRows'], 'validator: review words recomputed', $r['review'] );

$r = Validator::validate( $batch( array( 'border' => 'satin_color', 'borderColor' => 'gold' ) ), $cfg, $metrics );
$check( $r['ok'] && 'gold' === $r['batch']['borderColor'], 'validator: border colour' );

$r = Validator::validate( $batch( array( 'rows' => array() ) ), $cfg, $metrics );
$check( in_array( 'no_rows', $codes( $r ), true ), 'validator: no rows' );

$r = Validator::validate( 'garbage', $cfg, $metrics );
$check( ! $r['ok'], 'validator: garbage input' );

$r = Validator::validate( $batch( array( 'orgNote' => str_repeat( 'x', 300 ) . '<script>alert(1)</script>' ) ), $cfg, $metrics );
$check( 200 === mb_strlen( $r['batch']['orgNote'] ) && false === strpos( $r['batch']['orgNote'], '<' ), 'validator: orgNote cleaned and capped' );

// Server price on the normalised batch equals the vector of the same input.
$v25 = $batch( array( 'rows' => array_fill( 0, 25, array( 'name' => 'A', 'qty' => 1 ) ) ) );
$q   = Pricing::quote( Validator::validate( $v25, $cfg, $metrics )['batch'], $base, $cfg );
$check( 134.61 === $q['totalIncl'] && 122.51 === $q['lineIncl'], 'pricing: §1.7 control via validator', $q['totalIncl'] );

// Uppercase parity for ß / accents (mb_strtoupper must match JS toUpperCase for the cases we use).
$check( 'STRASSER' === Fit::apply_case( 'straßer', true, null ), 'apply_case: ß → SS', Fit::apply_case( 'straßer', true, null ) );
$check( 'ÉLODIE' === Fit::apply_case( 'élodie', true, null ), 'apply_case: accents kept' );

echo "nametape PHP: $pass ok, $fail fout\n";
exit( $fail ? 1 : 0 );
