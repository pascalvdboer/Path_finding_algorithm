<?php
/**
 * Prompt_Adapter tests (no WordPress, no network). Run: php tests/ai/run-prompt-adapter-php.php   Exit 1 on failure.
 * Covers SPEC-AI-DIENSTEN §7: REST/studio → brief input, "Zo begrepen we je", alt text, hints, LocalRefine,
 * public styles, colour hex, and the 30 customer questions of tests/ai/prompt-vectors.json end-to-end.
 *
 * @package BorduurPatchStudio
 */

define( 'BPS_TESTING', true );
$plugin = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio';
require_once $plugin . '/includes/ai/class-prompt-adapter.php';

use BorduurPatchStudio\AI\Prompt_Adapter as A;
use BorduurPatchStudio\AI\Prompt_Engine as E;

$cfg      = E::load_config();
$defaults = json_decode( (string) file_get_contents( $plugin . '/config/defaults.json' ), true );
$vectors  = json_decode( (string) file_get_contents( __DIR__ . '/prompt-vectors.json' ), true );
$fail     = 0;
$pass     = 0;

$check = static function ( string $id, bool $ok, string $msg ) use ( &$fail, &$pass ) {
	if ( $ok ) {
		++$pass;
		return;
	}
	++$fail;
	fwrite( STDERR, "FAIL {$id}: {$msg}\n" );
};
$eq = static function ( string $id, $want, $got ) use ( $check ) {
	$check( $id, $want === $got, 'verwacht ' . json_encode( $want, JSON_UNESCAPED_UNICODE ) . ', kreeg ' . json_encode( $got, JSON_UNESCAPED_UNICODE ) );
};
$in = static function ( array $rest, array $ctx = array() ) use ( $defaults, $cfg ): array {
	$rest += array( 'description' => 'een wolf', 'lang' => 'nl', 'text' => '', 'style' => 'auto', 'shape' => 'auto', 'maxColors' => null, 'textMode' => 'auto' );
	return A::input( $rest, $ctx, $defaults, $cfg );
};
$brief = static function ( array $rest, array $ctx = array() ) use ( $in, $cfg, $defaults ): array {
	return E::brief( $in( $rest, $ctx ), $cfg, $defaults );
};

/* ------------------------------------------------------------- input() */

$r = $in( array( 'description' => "een wolf\nmet\tbijl", 'text' => ' MC WOLF ', 'lang' => 'fr', 'style' => 'club', 'shape' => 'shield', 'maxColors' => 3, 'textMode' => 'ai' ), array( 'technique' => 'woven', 'sizeMm' => 100, 'fabric' => 'navy', 'edge' => 'merrow' ) );
$eq( 'I01 description', 'een wolf met bijl', $r['description'] );
$eq( 'I01 text', 'MC WOLF', $r['text'] );
$eq( 'I01 lang', 'fr', $r['lang'] );
$eq( 'I01 style', 'club', $r['style'] );
$eq( 'I01 shape', 'shield', $r['shape'] );
$eq( 'I01 maxColors', 3, $r['maxColors'] );
$eq( 'I01 textMode', 'ai', $r['textMode'] );
$eq( 'I01 technique', 'woven', $r['technique'] );
$eq( 'I01 sizeMm', 100, $r['sizeMm'] );
$eq( 'I01 fabric', 'navy', $r['fabric'] );
$eq( 'I01 edge', 'merrow', $r['edge'] );
$eq( 'I01 background', 'transparent', $r['background'] );
$check( 'I01 keys', array() === array_diff( array_keys( $r ), array( 'description', 'lang', 'style', 'shape', 'technique', 'text', 'textMode', 'reserveText', 'background', 'fabric', 'sizeMm', 'maxColors', 'edge' ) ), 'onverwachte sleutel: ' . implode( ',', array_keys( $r ) ) );
$check( 'I01 no prompt', ! isset( $r['prompt'] ) && ! isset( $r['brief'] ), 'adapter mag nooit een prompt doorgeven' );

$r = $in( array( 'lang' => 'de', 'style' => '_doc', 'shape' => 'hexagon', 'textMode' => 'loud', 'background' => 'rainbow' ), array( 'technique' => 'laser-etched', 'fabric' => 'tartan', 'edge' => 'velcro_hook' ) );
$eq( 'I02 lang fallback', 'nl', $r['lang'] );
$eq( 'I02 style fallback', 'auto', $r['style'] );
$eq( 'I02 shape fallback', 'auto', $r['shape'] );
$eq( 'I02 textMode fallback', 'auto', $r['textMode'] );
$eq( 'I02 background fallback', 'transparent', $r['background'] );
$eq( 'I02 technique fallback', $defaults['techniqueOrder'][0], $r['technique'] );
$eq( 'I02 fabric fallback', 'black', $r['fabric'] );
$check( 'I02 edge of other group dropped', ! isset( $r['edge'] ), 'velcro_hook is geen rand' );
$check( 'I02 maxColors absent', ! isset( $r['maxColors'] ), 'maxColors hoort weg te blijven (slim kiezen)' );

$eq( 'I03 studio reserves space', true, $in( array( 'textMode' => 'studio' ) )['reserveText'] );
$eq( 'I03 auto no reserve', false, $in( array() )['reserveText'] );
$eq( 'I03 background fabric (retry without transparency)', 'fabric', $in( array( 'background' => 'fabric' ) )['background'] );

// Fabrics: studio ids, aliases, hex (nearest studio fabric), unknown.
foreach ( array( 'bottle' => 'green', 'sand' => 'beige', 'olive' => 'olive', 'royal' => 'royal', 'GREY' => 'grey', '#1b2a4a' => 'navy', '#fefefe' => 'white', '#b02020' => 'red', '' => 'black' ) as $f => $want ) {
	$eq( "I04 fabric {$f}", $want, $in( array(), array( 'fabric' => $f ) )['fabric'] );
}
foreach ( array_keys( array_filter( $cfg['fabricColors'], static fn( $k ) => '_' !== $k[0], ARRAY_FILTER_USE_KEY ) ) as $f ) {
	$eq( "I04 own key {$f}", $f, $in( array(), array( 'fabric' => $f ) )['fabric'] );
}
foreach ( $defaults['fabrics'] as $fab ) {
	$got = $in( array(), array( 'fabric' => $fab['id'] ) )['fabric'];
	$check( "I04 studio fabric {$fab['id']}", isset( $cfg['fabricColors'][ $got ] ), "{$fab['id']} → {$got} bestaat niet in fabricColors" );
}

// Size.
$eq( 'I05 default size', (int) round( $defaults['sizes']['defaultCm'] * 10 ), $in( array() )['sizeMm'] );
$eq( 'I05 text-only default size', (int) round( $defaults['sizes']['defaultTextOnlyCm'] * 10 ), $in( array( 'description' => '', 'text' => 'JUNIOR CREW' ) )['sizeMm'] );
$eq( 'I05 clamp high', 300, $in( array(), array( 'sizeMm' => 1000 ) )['sizeMm'] );
$eq( 'I05 clamp low', 25, $in( array(), array( 'sizeMm' => '12' ) )['sizeMm'] );
$eq( 'I05 garbage', 80, $in( array(), array( 'sizeMm' => 'groot' ) )['sizeMm'] );

// Context from the REST body when no studio context is passed (Jobs::adapter_input passes both).
$eq( 'I06 rest context', 'pvc', $in( array( 'context' => array( 'technique' => 'pvc' ) ) )['technique'] );

// Shape from the studio: only a shape the customer chose, and never over words in the description.
$eq( 'I07 chosen studio shape', 'oval', $in( array( 'description' => 'een uil met een boek' ), array( 'shape' => 'oval', 'shapeChosen' => true ) )['shape'] );
$eq( 'I07 studio default ignored', 'auto', $in( array( 'description' => 'een uil met een boek' ), array( 'shape' => 'oval' ) )['shape'] );
$eq( 'I07 description wins (word)', 'auto', $in( array( 'description' => 'een ronde patch met een uil' ), array( 'shape' => 'oval', 'shapeChosen' => true ) )['shape'] );
$eq( 'I07 description wins (segment)', 'auto', $in( array( 'description' => 'een uil met een boek, schild' ), array( 'shape' => 'oval', 'shapeChosen' => true ) )['shape'] );
$eq( 'I07 explicit choice wins', 'rect', $in( array( 'shape' => 'rect' ), array( 'shape' => 'oval', 'shapeChosen' => true ) )['shape'] );
$eq( 'I07 brief uses it', 'oval', $brief( array( 'description' => 'een uil met een boek' ), array( 'shape' => 'oval', 'shapeChosen' => true ) )['shape'] );

// Colour count in the description.
$hints = array(
	'een uil, één kleur'                 => 1,
	'een uil in één kleur'               => 1,
	'une seule couleur, un hibou'        => 1,
	'un hibou unicolore'                 => 1,
	'monochroom logo met een uil'        => 1,
	'een uil in 2 kleuren'               => 2,
	'een uil in twee kleuren'            => 2,
	'drie garenkleuren'                  => 3,
	'un hibou en trois couleurs'         => 3,
	'een uil met een kleur die opvalt'   => null,
	'un hibou avec une couleur vive'     => null,
	'10 kleuren mag'                     => null,
	'een uil met 4 sterren'              => null,
);
foreach ( $hints as $d => $want ) {
	$eq( "I08 '{$d}'", $want, A::color_count_hint( $d ) );
}
$eq( 'I08 hint used', 1, $in( array( 'description' => 'een uil, één kleur' ) )['maxColors'] );
$eq( 'I08 explicit wins', 4, $in( array( 'description' => 'een uil, één kleur', 'maxColors' => 4 ) )['maxColors'] );
$eq( 'I08 explicit clamp', 8, $in( array( 'maxColors' => '9' ) )['maxColors'] );
$eq( 'I08 brief one colour', 1, $brief( array( 'description' => 'een uil, één kleur' ) )['maxColors'] );

$eq( 'I09 deterministic', $in( array( 'description' => 'un loup' ) ), $in( array( 'description' => 'un loup' ) ) );

/* ---------------------------------------------------------- understood() */

$b = $brief( array( 'description' => 'een wolf met een bijl voor onze motorclub, rond, zwart-rood', 'text' => 'MC WOLVES 1998' ) );
$u = A::understood( $b, 'nl', $cfg );
$eq( 'U01 summary', 'wolf met een bijl voor onze motorclub · rond · zwart en rood · stijl club / vereniging', $u['summary'] );
$eq( 'U01 subject', 'wolf met een bijl voor onze motorclub', $u['subject'] );
$eq( 'U01 colors hex', array( '#1b1b1b', '#c62828' ), $u['colors'] );
$eq( 'U01 colorLabels', array( 'zwart', 'rood' ), $u['colorLabels'] );
$eq( 'U01 textMode', 'studio', $u['textMode'] );
$eq( 'U01 text', 'MC WOLVES 1998', $u['text'] );
$check( 'U01 text chip', in_array( 'tekst «MC WOLVES 1998» zet je in de studio', array_column( $u['chips'], 'label' ), true ), 'tekstchip ontbreekt' );
foreach ( array( 'summary' => 'string', 'style' => 'string', 'shape' => 'string', 'colors' => 'array', 'maxColors' => 'integer', 'textMode' => 'string', 'text' => 'string', 'placement' => 'string', 'warnings' => 'array' ) as $k => $type ) {
	$check( "U02 schema {$k}", array_key_exists( $k, $u ) && gettype( $u[ $k ] ) === $type, "{$k} ontbreekt of is geen {$type}" ); // SPEC §4.3.
}
$json = json_encode( $u, JSON_UNESCAPED_UNICODE );
foreach ( array( 'RULES', 'SUBJECT:', '<<', 'axe', 'motorcycle', 'embroidery', 'gradient' ) as $bad ) {
	$check( "U03 no prompt '{$bad}'", false === strpos( $json, $bad ), "understood lekt promptwoord '{$bad}'" );
}

$uf = A::understood( $brief( array( 'lang' => 'fr', 'description' => 'un loup avec une hache pour notre club de moto, rond, noir et rouge' ) ), 'fr', $cfg );
$eq( 'U04 fr summary', 'loup avec une hache pour notre club de moto · rond · noir et rouge · style club / association', $uf['summary'] );

$ub = A::understood( $brief( array( 'description' => 'alleen tekst "JUNIOR CREW 2026"' ) ), 'nl', $cfg );
$eq( 'U05 typographic subject', 'enkel tekst', $ub['subject'] );
$check( 'U05 ai text chip', in_array( 'tekst in het ontwerp: «JUNIOR CREW 2026»', array_column( $ub['chips'], 'label' ), true ), json_encode( $ub['chips'], JSON_UNESCAPED_UNICODE ) );
$check( 'U05 maxColors chip (no colours named)', in_array( 'maxColors', array_column( $ub['chips'], 'kind' ), true ), 'chip max. kleuren ontbreekt' );

$up = A::understood( $brief( array( 'description' => 'een uil, mail me op jan.peeters@example.com of bel 0475 12 34 56' ) ), 'nl', $cfg );
$check( 'U06 privacy warning', in_array( 'personal_data_removed', $up['warnings'], true ), 'waarschuwing ontbreekt' );
$check( 'U06 privacy note nl', 'personal_data_removed' === ( $up['notes'][0]['code'] ?? '' ) && false !== strpos( $up['notes'][0]['message'], 'persoonlijke gegevens' ), json_encode( $up['notes'], JSON_UNESCAPED_UNICODE ) );
$check( 'U06 no personal data', ! preg_match( '/example\.com|0475|peeters/i', json_encode( $up ) ), 'persoonsgegevens in understood' );
$upf = A::understood( $brief( array( 'description' => 'een uil, mail me op jan@example.com' ) ), 'fr', $cfg );
$check( 'U06 privacy note fr', false !== strpos( $upf['notes'][0]['message'] ?? '', 'données personnelles' ), 'FR-melding ontbreekt' );

$long = A::understood( $brief( array( 'description' => 'een brullende leeuw met een kroon en een zwaard boven een kasteel met twee torens en een vlag voor de schutterij van ons dorp' ) ), 'nl', $cfg );
$check( 'U07 subject capped', mb_strlen( $long['subject'] ) <= A::SUBJECT_MAX && '…' === mb_substr( $long['subject'], -1 ), "te lang: {$long['subject']}" );

$um = A::understood( $brief( array( 'description' => 'een eenhoorn, regenboog' ) ), 'nl', $cfg );
$check( 'U08 unknown words kept', false !== strpos( $um['subject'], 'eenhoorn' ), $um['subject'] );

$ua = A::understood( $brief( array( 'description' => 'scouts: een vos rond een kampvuur onder de sterren, groen en oranje' ) ), 'nl', $cfg );
$eq( 'U09 rond = around, not a shape', 'scouts, vos rond een kampvuur onder de sterren', $ua['subject'] );

$ut = A::understood( $brief( array( 'description' => 'een uil met een boek, één kleur, wit' ) ), 'nl', $cfg );
$eq( 'U10 one colour', 1, $ut['maxColors'] );
$eq( 'U10 fallback empty brief', 'eigen ontwerp', A::understood( array( 'style' => 'badge', 'shape' => 'circle' ), 'nl', $cfg )['subject'] );
$eq( 'U10 deterministic', $u, A::understood( $b, 'nl', $cfg ) );

/* ------------------------------------------------------- alt(), hints() */

$eq( 'A01 alt nl', 'Voorstel 2: wolf met een bijl voor onze motorclub, rond, zwart en rood', A::alt( $b, 'nl', 1, $cfg ) );
$eq( 'A02 alt fr', 'Proposition 1 : loup avec une hache pour notre club de moto, rond, noir et rouge', A::alt( $brief( array( 'lang' => 'fr', 'description' => 'un loup avec une hache pour notre club de moto, rond, noir et rouge' ) ), 'fr', 0, $cfg ) );

$h = A::hints( $b, $cfg );
$eq( 'H01 hints', array( 'shape' => 'circle', 'maxColors' => $b['maxColors'], 'colors' => array( '#1b1b1b', '#c62828' ), 'textMode' => 'studio', 'text' => 'MC WOLVES 1998', 'placement' => $b['placement'], 'background' => 'transparent' ), $h );

/* ------------------------------------------------------- local_refine() */

$lr = A::local_refine( array( 'route' => 'local', 'action' => 'open_colors', 'message' => 'm', 'colors' => array( 'royal blue', 'white', 'nonsense' ) ), 1, $cfg );
$eq( 'L01 body', array( 'route' => 'local', 'action' => 'open_colors', 'message' => 'm', 'colors' => array( '#2244aa', '#ffffff' ), 'text' => null, 'shape' => null, 'aiOffer' => array( 'cost' => 1 ) ), $lr );
$eq( 'L02 unknown action', 'open_colors', A::local_refine( array( 'action' => 'drop_tables' ), 1, $cfg )['action'] );
$eq( 'L03 bad shape', null, A::local_refine( array( 'action' => 'open_shape', 'shape' => 'star' ), 1, $cfg )['shape'] );
$eq( 'L04 good shape', 'oval', A::local_refine( array( 'action' => 'open_shape', 'shape' => 'oval' ), 1, $cfg )['shape'] );
$eq( 'L05 text', 'MC WOLF', A::local_refine( array( 'action' => 'open_text', 'text' => 'MC WOLF' ), 2, $cfg )['text'] );
$eq( 'L05 cost', 2, A::local_refine( array( 'action' => 'open_text' ), 2, $cfg )['aiOffer']['cost'] );
foreach ( $cfg['refineLocal'] as $key => $rule ) {
	if ( is_array( $rule ) && isset( $rule['action'] ) ) {
		$check( "L06 refineLocal.{$key}", in_array( $rule['action'], A::LOCAL_ACTIONS, true ), "actie {$rule['action']} kent de studio niet (SPEC §10.3)" );
	}
}

/* ------------------------------------------------ public_styles(), hex */

foreach ( array( 'nl', 'fr' ) as $lang ) {
	$ps = A::public_styles( $lang, $cfg );
	$eq( "P01 7 styles {$lang}", 7, count( $ps ) );
	foreach ( $ps as $s ) {
		$check( "P02 {$lang} {$s['id']}", array( 'id', 'label', 'hint' ) === array_keys( $s ) && '' !== $s['label'] && '' !== $s['hint'], json_encode( $s ) );
		$check( "P03 no english fragment {$s['id']}", false === strpos( json_encode( $s ), $cfg['styles'][ $s['id'] ]['en'] ), 'Engels fragment gelekt' );
	}
}
$eq( 'C01 hex', array( '#1b1b1b', '#abcdef' ), A::colors_hex( array( 'black', 'BLACK', '#ABCDEF', 'nonsense', '#xyz' ), $cfg ) );
foreach ( $cfg['glossary']['color'] as $k => $en ) {
	if ( '_doc' !== $k ) {
		$check( "C02 {$en}", 1 === count( A::colors_hex( array( $en ), $cfg ) ), "geen hex voor {$en}" );
	}
}

/* ---------------------------------- the 30 customer questions, end-to-end */

foreach ( $vectors['translations'] as $v ) {
	$id   = 'V' . $v['id'];
	$lang = $v['input']['lang'];
	$rest = $v['input'] + array( 'style' => 'auto', 'shape' => 'auto', 'maxColors' => null, 'textMode' => 'auto' );
	$bi   = E::brief( A::input( $rest, array(), $defaults, $cfg ), $cfg, $defaults );
	$check( $id, empty( $bi['blocked'] ), 'geblokkeerd via adapter' );
	if ( ! empty( $bi['blocked'] ) ) {
		continue;
	}
	$un = A::understood( $bi, $lang, $cfg );
	$check( "{$id} summary", '' !== $un['subject'] && substr_count( $un['summary'], ' · ' ) >= 2, $un['summary'] );
	$check( "{$id} no prompt", false === strpos( json_encode( $un ), '<<' ) && false === strpos( json_encode( $un ), 'RULES' ), 'prompt gelekt' );
	$check( "{$id} hex", count( $un['colors'] ) === count( $bi['colors'] ), 'kleur zonder hex: ' . implode( ',', $bi['colors'] ) );
	$check( "{$id} alt", 0 === strpos( A::alt( $bi, $lang, 0, $cfg ), 'fr' === $lang ? 'Proposition 1 : ' : 'Voorstel 1: ' ), 'alt' );
	$built = E::build( $bi, $cfg );
	$check( "{$id} build", false !== strpos( $built['prompt'], 'RULES:' ), 'build zonder regels' );
}

echo ( $fail ? "FAILED {$fail}" : 'OK' ) . " — prompt-adapter: {$pass} checks\n";
exit( $fail ? 1 : 0 );
