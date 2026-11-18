<?php
/**
 * Cross-sell: PHP-tweelingen tegen dezelfde vectors als de node-test. Exit 1 bij een afwijking.
 *   php tests/crosssell/run-php.php          → controleert de verwachtingen
 *   php tests/crosssell/run-php.php --dump   → JSON met alle resultaten (crosssell.test.js vergelijkt die met JS)
 *
 * @package BorduurPatchStudio
 */

define( 'BPS_TESTING', true );
$plugin = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/';
require_once $plugin . 'includes/class-pricing.php';
foreach ( array( 'fit', 'rules', 'pricing', 'messages', 'offers' ) as $f ) {
	require_once $plugin . 'includes/crosssell/class-' . $f . '.php';
}

use BorduurPatchStudio\Crosssell\Fit;
use BorduurPatchStudio\Crosssell\Rules;
use BorduurPatchStudio\Crosssell\Pricing as CsPricing;
use BorduurPatchStudio\Crosssell\Offers;
use BorduurPatchStudio\Crosssell\Messages;
use BorduurPatchStudio\Pricing;

/**
 * JSON lezen.
 *
 * @param string $path Pad.
 */
function bps_cs_json( string $path ): array {
	return json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
}

/**
 * Deep merge zoals shared/config.js.
 *
 * @param mixed $base Basis.
 * @param mixed $over Over.
 * @return mixed
 */
function bps_cs_merge( $base, $over ) {
	$is_obj = static fn( $v ) => is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );
	if ( ! $is_obj( $base ) || ! $is_obj( $over ) ) {
		return $over;
	}
	foreach ( $over as $k => $v ) {
		$base[ $k ] = ( isset( $base[ $k ] ) && $is_obj( $base[ $k ] ) && $is_obj( $v ) ) ? bps_cs_merge( $base[ $k ], $v ) : $v;
	}
	return $base;
}

/**
 * Vergelijk na JSON-normalisatie (45.0 === 45 zoals in JS).
 *
 * @param mixed $a A.
 * @param mixed $b B.
 */
function bps_cs_same( $a, $b ): bool {
	return json_decode( json_encode( $a ), true ) == json_decode( json_encode( $b ), true ); // phpcs:ignore Universal.Operators.StrictComparisons
}

$main     = bps_cs_json( $plugin . 'config/defaults.json' );
$cs       = bps_cs_json( $plugin . 'config/crosssell.json' );
$fitv     = bps_cs_json( __DIR__ . '/fit-vectors.json' );
$rulesv   = bps_cs_json( __DIR__ . '/rules-vectors.json' );
$pricev   = bps_cs_json( __DIR__ . '/price-vectors.json' );
$samples  = bps_cs_json( __DIR__ . '/samples.json' );
$dump     = in_array( '--dump', $argv, true );
$fail     = 0;
$checks   = 0;
$item     = static function ( string $id, array $cfg ) {
	foreach ( $cfg['items'] as $i ) {
		if ( $i['id'] === $id ) {
			return $i;
		}
	}
	return array();
};
$patch_unit = static fn( $t, $s, $o, $q, $p ) => Pricing::cart_unit_excl(
	array(
		'technique' => $t,
		'size_cm'   => $s,
		'options'   => $o,
	),
	(int) $q,
	(int) $p,
	$main
);
$check = static function ( bool $ok, string $what ) use ( &$fail, &$checks, $dump ): void {
	++$checks;
	if ( ! $ok ) {
		++$fail;
		if ( ! $dump ) {
			fwrite( STDERR, "FOUT: $what\n" );
		}
	}
};
$out = array(
	'fit'        => array(),
	'variations' => array(),
	'rules'      => array(),
	'prices'     => array(),
	'designs'    => array(),
	'offers'     => array(),
	'messages'   => Messages::ALL,
);

foreach ( $fitv['fit'] as $v ) {
	$d   = Fit::design_from_payload( bps_cs_merge( $fitv['base'], $v['design'] ?: array() ) );
	$r   = Fit::item( $item( $v['item'], $cs ), $d, array( 'tech' => $main['techniques'][ $d['technique'] ] ?? null, 'cartQty' => $d['qty'] ) );
	$out['fit'][] = $r;
	foreach ( $v['expect'] as $k => $e ) {
		$check( bps_cs_same( $r[ $k ] ?? null, $e ), "fit {$v['name']}: $k = " . json_encode( $r[ $k ] ?? null ) . ', verwacht ' . json_encode( $e ) );
	}
}
foreach ( $fitv['variations'] as $v ) {
	$r                     = Fit::pick_default_variation( $v['variations'], $v['edgeHex'], $cs['defaultColors'] );
	$out['variations'][]   = $r;
	$check( $r === $v['expect'], "variatie {$v['name']}: $r" );
}
foreach ( $rulesv['rules'] as $v ) {
	$cfg  = bps_cs_merge( $cs, $v['cfg'] ?? array() );
	$fits = array();
	if ( 'all' === $v['fits'] ) {
		foreach ( $cfg['items'] as $i ) {
			if ( ! array_key_exists( 'enabled', $i ) || false !== $i['enabled'] ) {
				$fits[ $i['id'] ] = array( 'fit' => 'ok' );
			}
		}
	} else {
		foreach ( $v['fits'] as $k => $f ) {
			$fits[ $k ] = array( 'fit' => $f );
		}
	}
	$r              = Rules::pick( $fits, $v['context'], $cfg, $v['stats'] ?? array() );
	$out['rules'][] = $r;
	$check( $r['ids'] === $v['expect']['ids'], "regels {$v['name']}: " . implode( ',', $r['ids'] ) );
	if ( isset( $v['expect']['rule'] ) ) {
		$check( $r['rule'] === $v['expect']['rule'], "regels {$v['name']}: regel {$r['rule']}" );
	}
}
foreach ( $pricev['prices'] as $v ) {
	$r = CsPricing::item(
		$item( $v['item'], $cs ),
		$v['input'],
		array(
			'cfg'        => $cs,
			'main'       => $main,
			'patch_unit' => $patch_unit,
			'locale'     => $v['locale'] ?? 'nl-BE',
		)
	);
	$out['prices'][] = $r;
	$e               = $v['expect'];
	foreach ( array( 'ok', 'errors', 'unitExcl', 'unitIncl', 'lineIncl', 'lineExcl', 'tierDiscount' ) as $k ) {
		if ( array_key_exists( $k, $e ) ) {
			$check( bps_cs_same( $r[ $k ], $e[ $k ] ), "prijs {$v['name']}: $k = " . json_encode( $r[ $k ] ) . ', verwacht ' . json_encode( $e[ $k ] ) );
		}
	}
	if ( isset( $e['parts'] ) ) {
		$check( bps_cs_same( array_column( $r['parts'], 'incl' ), $e['parts'] ), "prijs {$v['name']}: parts" );
	}
	if ( isset( $e['fees'] ) ) {
		$check( bps_cs_same( array_column( $r['fees'], 'incl' ), $e['fees'] ), "prijs {$v['name']}: fees" );
	}
	if ( isset( $e['labels'] ) ) {
		$check( array_column( $r['parts'], 'label' ) === $e['labels'], "prijs {$v['name']}: labels" );
	}
}

$linked = Offers::link_products( $cs, $samples['productLinks'] );
$catalog = array();
foreach ( $samples['catalog'] as $id => $p ) {
	$catalog[ (int) $id ] = $p;
}
foreach ( $samples['designs'] as $s ) {
	$out['designs'][] = Fit::design_from_payload( $s['payload'] );
	$o                = Offers::build(
		array( 'payload' => $s['payload'] ),
		array(
			'cfg'        => $linked,
			'main'       => $main,
			'catalog'    => $catalog,
			'patch_unit' => $patch_unit,
			'locale'     => 'nl-BE',
			'month'      => 6,
		)
	);
	$ids              = array_column( $o['cards'], 'id' );
	$out['offers'][]  = array_map(
		static fn( $c ) => array(
			'id'                 => $c['id'],
			'fit'                => $c['fit'],
			'unitIncl'           => $c['unitIncl'],
			'defaultQty'         => $c['defaultQty'],
			'defaultVariationId' => $c['defaultVariationId'],
			'patchSizeCm'        => $c['patchSizeCm'],
			'adjustNote'         => $c['adjustNote'],
			'title'              => $c['title'],
			'note'               => $c['note'],
			'breakdown'          => $c['breakdown'],
			'ladder'             => $c['price']['patchLadder'],
			'variations'         => count( $c['variations'] ),
		),
		$o['cards']
	);
	if ( isset( $s['expect']['ids'] ) ) {
		$a = $ids;
		$b = $s['expect']['ids'];
		sort( $a );
		sort( $b );
		$check( $a === $b, "kaarten {$s['id']}: " . implode( ',', $ids ) );
	}
	foreach ( $s['expect']['notIds'] ?? array() as $n ) {
		$check( ! in_array( $n, $ids, true ), "kaarten {$s['id']} toont $n" );
	}
	foreach ( $s['expect']['includes'] ?? array() as $n ) {
		$check( in_array( $n, $ids, true ), "kaarten {$s['id']} mist $n" );
	}
}

// Geldnotatie zoals Intl in de browser.
$check( Messages::money( 96.8, 'nl-BE' ) === "€\u{00A0}96,80", 'geld nl' );
$check( Messages::money( 1234.5, 'fr-BE' ) === "1\u{202F}234,50\u{00A0}€", 'geld fr' );
$check( array_keys( Messages::ALL['nl'] ) === array_keys( Messages::ALL['fr'] ), 'NL/FR-sleutels' );

if ( $dump ) {
	echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	exit( $fail ? 1 : 0 );
}
echo $fail ? "crosssell PHP: $fail van $checks controles mislukt\n" : "crosssell PHP: alle $checks controles in orde\n";
exit( $fail ? 1 : 0 );
