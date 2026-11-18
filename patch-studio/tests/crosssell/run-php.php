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
foreach ( array( 'fit', 'rules', 'pricing', 'messages', 'offers', 'lines' ) as $f ) {
	require_once $plugin . 'includes/crosssell/class-' . $f . '.php';
}

use BorduurPatchStudio\Crosssell\Fit;
use BorduurPatchStudio\Crosssell\Rules;
use BorduurPatchStudio\Crosssell\Pricing as CsPricing;
use BorduurPatchStudio\Crosssell\Offers;
use BorduurPatchStudio\Crosssell\Messages;
use BorduurPatchStudio\Crosssell\Lines;
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

// ---- Lines: verzoek normaliseren tegen de server-kaart, mandcontext, eigen fees (SPEC-EXTRA §2.6–2.7) ----
$build = static function ( string $sid, array $cart = array() ) use ( $samples, $linked, $main, $catalog, $patch_unit ): array {
	foreach ( $samples['designs'] as $s ) {
		if ( $s['id'] === $sid ) {
			return Offers::build(
				array( 'payload' => $s['payload'] ),
				array( 'cfg' => $linked, 'main' => $main, 'catalog' => $catalog, 'patch_unit' => $patch_unit, 'locale' => 'nl-BE', 'month' => 6, 'cart' => $cart )
			);
		}
	}
	return array();
};
$card_of = static function ( array $o, string $id ): ?array {
	foreach ( $o['cards'] as $c ) {
		if ( $c['id'] === $id ) {
			return $c;
		}
	}
	return null;
};
$o25  = $build( 'flat25' );
$cap  = $card_of( $o25, 'cap' );
$hood = $card_of( $o25, 'hoodie' );
$n    = Lines::normalize( array( 'lines' => array( array( 'productId' => $cap['defaultProductId'], 'variationId' => $cap['defaultVariationId'], 'qty' => 25 ) ) ), $cap );
$check( $n['ok'] && 1 === count( $n['lines'] ) && 'front' === $n['placement'], 'lines: pet één klik is geldig' );
$check( ! Lines::normalize( array( 'lines' => array( array( 'productId' => 999, 'variationId' => 0, 'qty' => 1 ) ) ), $cap )['ok'], 'lines: vreemd product geweigerd' );
$check( in_array( 'variation', Lines::normalize( array( 'lines' => array( array( 'productId' => $cap['defaultProductId'], 'variationId' => 1, 'qty' => 1 ) ) ), $cap )['errors'], true ), 'lines: vreemde variatie geweigerd' );
$check( in_array( 'qty', Lines::normalize( array( 'lines' => array( array( 'productId' => $cap['defaultProductId'], 'variationId' => $cap['defaultVariationId'], 'qty' => 0 ) ) ), $cap )['errors'], true ), 'lines: aantal 0 geweigerd' );
$two = array( array( 'productId' => $cap['defaultProductId'], 'variationId' => $cap['defaultVariationId'], 'qty' => 1 ), array( 'productId' => $cap['defaultProductId'], 'variationId' => $cap['defaultVariationId'], 'qty' => 1 ) );
$check( ! Lines::normalize( array( 'lines' => $two ), $cap )['ok'], 'lines: pet = één regel' );
$check( in_array( 'option', Lines::normalize( array( 'lines' => array( $two[0] ), 'options' => array( 'gratis' ) ), $cap )['errors'], true ), 'lines: onbekende optie geweigerd' );
$check( in_array( 'placement', Lines::normalize( array( 'lines' => array( $two[0] ), 'placement' => 'back' ), $cap )['errors'], true ), 'lines: rug kan niet op een pet' );
$sizes = array_values( array_filter( $hood['variations'], static fn( $v ) => $v['productId'] === $hood['defaultProductId'] && $v['inStock'] ) );
$hl    = array(
	array( 'productId' => $hood['defaultProductId'], 'variationId' => $sizes[0]['id'], 'qty' => 10 ),
	array( 'productId' => $hood['defaultProductId'], 'variationId' => $sizes[1]['id'], 'qty' => 15 ),
);
$check( Lines::normalize( array( 'lines' => $hl ), $hood )['ok'], 'lines: hoodie mag meerdere maatregels' );
$check( ! Lines::normalize( array( 'lines' => array( $hl[0], $hl[0] ) ), $hood )['ok'], 'lines: dubbele maatregel geweigerd' );

$op   = $build( 'photo1' );
$fr   = $card_of( $op, 'frame' );
$fl   = array( array( 'productId' => $fr['defaultProductId'], 'variationId' => 0, 'qty' => 1 ) );
$fn   = Lines::normalize( array( 'lines' => $fl, 'plate' => array( 'l1' => "<b>Bram</b>\u{202E} & Lotte", 'l2' => str_repeat( 'x', 60 ) ) ), $fr );
$check( $fn['ok'] && 'Bram & Lotte' === $fn['plate']['l1'] && 28 === mb_strlen( $fn['plate']['l2'] ), 'lines: plaatje opgekuist en max. 28 tekens' );
$lk   = $card_of( $op, 'leather_keychain' );
$ll   = array( array( 'productId' => $lk['defaultProductId'], 'variationId' => 0, 'qty' => 2 ) );
$check( Lines::normalize( array( 'lines' => $ll, 'options' => array( 'name_back' ), 'names' => array( 'Lien', ' Bram ' ) ), $lk )['names'] === array( 'Lien', 'Bram' ), 'lines: namen achterop' );
$check( in_array( 'names_qty', Lines::normalize( array( 'lines' => $ll, 'options' => array( 'name_back' ), 'names' => array( 'Lien' ) ), $lk )['errors'], true ), 'lines: aantal = aantal namen' );
$check( in_array( 'names_qty', Lines::normalize( array( 'lines' => $ll, 'options' => array( 'name_back' ), 'names' => array( 'Lien', 'Bram', 'Noor' ) ), $lk )['errors'], true ), 'lines: te veel namen geweigerd' );

$opv  = $build( 'pvc50' );
$pvc  = $card_of( $opv, 'pvc_keychain' );
$pl   = static fn( $q ) => array( 'lines' => array( array( 'productId' => $pvc['defaultProductId'], 'variationId' => 0, 'qty' => $q ) ) );
$check( Lines::normalize( $pl( 60 ), $pvc )['ok'], 'lines: PVC 60 stuks ok' );
$check( in_array( 'qty_rule', Lines::normalize( $pl( 40 ), $pvc )['errors'], true ), 'lines: PVC onder het minimum geweigerd' );
$check( in_array( 'qty_rule', Lines::normalize( $pl( 55 ), $pvc )['errors'], true ), 'lines: PVC buiten de stappen geweigerd' );

// Mandcontext en pool.
$bps  = static fn( $id, $t, $q ) => array( 'quantity' => $q, 'product_id' => 1, 'bps' => array( 'design_id' => $id, 'technique' => $t, 'ref' => 'PS-' . $id ) );
$csl  = static fn( $id, $item, $type, $t, $q ) => array( 'quantity' => $q, 'product_id' => 2, 'bps_cs' => array( 'design_id' => $id, 'item' => $item, 'type' => $type, 'technique' => $t, 'ref' => 'PS-' . $id ) );
$cart = array( $bps( 7, 'embroidered', 25 ), $csl( 7, 'cap', 'patch_on_blank', 'embroidered', 25 ), $csl( 7, 'leather_keychain', 'laser', 'embroidered', 3 ), $bps( 8, 'woven', 10 ) );
$ctx  = Lines::context( $cart, 7, $main );
$check( 25 === $ctx['qtyOfDesign'] && 50 === $ctx['poolQty'] && array( 'embroidery' ) === $ctx['feeGroups'], 'context: aantal van het ontwerp, pool met patches op dragers, betaalde groepen' );
$check( null === Lines::context( $cart, 99, $main )['qtyOfDesign'], 'context: ontwerp niet in de mand' );
$check( 8 === Lines::last_design_id( $cart ), 'context: laatste ontwerp' );

// Fees: borduurkaart enkel als er geen patchregel van die groep meer is; nooit dubbel; PVC-mal één keer.
$check( array() === Lines::fees( $cart, $main, $linked, array(), 'nl' ), 'fees: patchregel betaalt al de borduurkaart' );
$only = array( $csl( 7, 'cap', 'patch_on_blank', 'embroidered', 25 ), $csl( 7, 'beanie', 'patch_on_blank', 'embroidered', 10 ) );
$f    = Lines::fees( $only, $main, $linked, array(), 'nl' );
$check( 1 === count( $f ) && 'bps-fee-embroidery' === $f[0]['id'] && 45.0 === $f[0]['excl'], 'fees: patch enkel op dragers → één borduurkaart (zelfde id als woo)' );
$check( array() === Lines::fees( $only, $main, $linked, array( 'embroidery' ), 'nl' ), 'fees: oud product rekent de kaart al aan' );
$mdes = bps_cs_merge( $main, array( 'fees' => array( 'scope' => 'design' ) ) );
$fd   = Lines::fees( array_merge( $only, array( $csl( 9, 'cap', 'patch_on_blank', 'embroidered', 5 ) ) ), $mdes, $linked, array(), 'nl' );
$check( array( 'bps-fee-embroidery-7', 'bps-fee-embroidery-9' ) === array_column( $fd, 'id' ), 'fees: scope design → per ontwerp' );
$pv   = array( $csl( 7, 'pvc_keychain', 'pvc', 'pvc', 50 ), $csl( 7, 'pvc_keychain', 'pvc', 'pvc', 10 ) );
$fp   = Lines::fees( $pv, $main, $linked, array(), 'nl' );
$check( 1 === count( $fp ) && Lines::MOLD_FEE_ID === $fp[0]['id'] && 80.0 === $fp[0]['excl'], 'fees: PVC-mal één keer' );
$pvb  = array_merge( $pv, array( $bps( 7, 'pvc', 50 ) ) );
$check( 1 === count( Lines::fees( $pvb, $main, $linked, array(), 'nl' ) ), 'fees: PVC-patch in de mand deelt de mal NIET zonder sharedMold' );
$sh   = $linked;
foreach ( $sh['items'] as &$i ) {
	if ( 'pvc_keychain' === $i['id'] ) {
		$i['sharedMold'] = true;
	}
}
unset( $i );
$check( array() === Lines::fees( $pvb, $main, $sh, array(), 'nl' ), 'fees: gedeelde mal enkel met sharedMold + PVC-patch van hetzelfde ontwerp' );
$check( 'Met patch PS-7 (6 cm, geborduurd), op het voorpaneel genaaid' === Lines::patch_text( array( 'type' => 'patch_on_blank', 'ref' => 'PS-7', 'patch_size_cm' => 6, 'placement' => 'front' ), 'geborduurd', 'nl' ), 'tekst: mandregel NL' );
$check( 'Avec écusson PS-7 (7,5 cm, brodé), cousu dans le dos' === Lines::patch_text( array( 'type' => 'patch_on_blank', 'ref' => 'PS-7', 'patch_size_cm' => 7.5, 'placement' => 'back' ), 'brodé', 'fr' ), 'tekst: mandregel FR' );

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
