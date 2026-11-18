<?php
/**
 * Prompt-engine tests (no WordPress). Run: php tests/ai/run-prompt-php.php   Exit code 1 on any failure.
 * Vectors: tests/ai/prompt-vectors.json. Also checks invariants on every generated prompt.
 *
 * @package BorduurPatchStudio
 */

define( 'BPS_TESTING', true );
$plugin = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio';
require_once $plugin . '/includes/ai/class-ai-prompt-engine.php';
require_once $plugin . '/includes/ai/class-prompt-adapter.php';

use BorduurPatchStudio\AI\Prompt_Engine as E;
use BorduurPatchStudio\AI\Prompt_Adapter as A;

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

$invariants = static function ( string $id, string $prompt ) use ( $check ) {
	$check( $id, false !== stripos( $prompt, 'at most' ) || false !== stripos( $prompt, 'one single dark color' ), 'prompt mist kleurlimiet' );
	foreach ( array( 'no gradients', 'outlines', 'original', 'no watermark', 'margin' ) as $must ) {
		$check( $id, false !== stripos( $prompt, $must ), "prompt mist regel '{$must}'" );
	}
	$check( $id, false === strpos( $prompt, '{' ), 'onvervangen placeholder in prompt' );
	$check( $id, ! preg_match( '/[\w.+-]+@[\w-]+\.\w+/', $prompt ), 'e-mailadres in prompt' );
	$cust = strpos( $prompt, '<<' );
	$rules = strpos( $prompt, 'RULES:' );
	$check( $id, false === $cust || ( false !== $rules && $rules > $cust ), 'regels moeten NA de klantwoorden staan' );
};

$briefs = array();
foreach ( $vectors['translations'] as $v ) {
	$id = $v['id'];
	// "adapter": true = the real REST path (Prompt_Adapter::input() first), as Jobs::generate() does it.
	$in = empty( $v['adapter'] ) ? $v['input'] : A::input( $v['input'], (array) ( $v['context'] ?? array() ), $defaults, $cfg );
	$b  = E::brief( $in, $cfg, $defaults );
	$check( $id, empty( $b['blocked'] ), 'onverwacht geblokkeerd: ' . ( $b['code'] ?? '' ) );
	if ( ! empty( $b['blocked'] ) ) {
		continue;
	}
	$briefs[ $id ] = $b;
	$out           = E::build( $b, $cfg );
	$prompt        = $out['prompt'];
	$invariants( $id, $prompt );
	foreach ( $v['expect'] as $k => $want ) {
		switch ( $k ) {
			case 'subjectContains':
				foreach ( $want as $w ) {
					$check( $id, false !== strpos( $b['subjectEn'], $w ), "subject '{$b['subjectEn']}' mist '{$w}'" );
				}
				break;
			case 'promptContains':
				foreach ( $want as $w ) {
					$check( $id, false !== strpos( $prompt, $w ), "prompt mist '{$w}'" );
				}
				break;
			case 'promptNotContains':
				foreach ( $want as $w ) {
					$check( $id, false === strpos( $prompt, $w ), "prompt bevat '{$w}'" );
				}
				break;
			case 'understoodSummary':
				$u = A::understood( $b, $b['lang'], $cfg );
				$check( $id, $u['summary'] === $want, "understood.summary: verwacht '{$want}', kreeg '{$u['summary']}'" );
				break;
			case 'warnings':
				foreach ( $want as $w ) {
					$check( $id, in_array( $w, $b['warnings'], true ), "waarschuwing '{$w}' ontbreekt" );
				}
				break;
			default:
				$check( $id, ( $b[ $k ] ?? null ) === $want, "{$k}: verwacht " . json_encode( $want, JSON_UNESCAPED_UNICODE ) . ', kreeg ' . json_encode( $b[ $k ] ?? null, JSON_UNESCAPED_UNICODE ) );
		}
	}
	$check( $id, count( $b['colors'] ) <= $b['maxColors'], 'meer kleuren dan maxColors' );
	$check( $id, in_array( $out['params']['size'], array( '1024x1024', '1536x1024' ), true ), 'ongeldige size' );
	$check( $id, E::build( $b, $cfg ) === $out, 'niet deterministisch' );
}

foreach ( $vectors['safety'] as $v ) {
	$id = $v['id'];
	$b  = E::brief( $v['input'], $cfg, $defaults );
	if ( ! empty( $v['expect']['ok'] ) ) {
		$check( $id, empty( $b['blocked'] ), 'onterecht geblokkeerd: ' . ( $b['code'] ?? '' ) . ' ' . ( $b['match'] ?? '' ) );
		continue;
	}
	$check( $id, ! empty( $b['blocked'] ) && $b['code'] === $v['expect']['code'], 'verwacht blok ' . $v['expect']['code'] . ', kreeg ' . json_encode( $b['code'] ?? 'geen blok' ) );
	foreach ( array( 'nl', 'fr' ) as $lang ) {
		$check( $id, '' !== E::message( $v['expect']['code'], $lang, $cfg ), "geen {$lang}-melding" );
	}
}

foreach ( $vectors['refine'] as $v ) {
	$id = $v['id'];
	$r  = E::refine( $briefs[ $v['base'] ], $v['instruction'], $cfg, array(), ! empty( $v['forceAi'] ) );
	foreach ( $v['expect'] as $k => $want ) {
		if ( 'promptContains' === $k || 'promptNotContains' === $k ) {
			foreach ( $want as $w ) {
				$has = false !== strpos( $r['prompt'] ?? '', $w );
				$check( $id, 'promptContains' === $k ? $has : ! $has, "{$k} '{$w}' — prompt: " . ( $r['prompt'] ?? '' ) );
			}
			continue;
		}
		$check( $id, ( $r[ $k ] ?? null ) === $want, "{$k}: verwacht " . json_encode( $want ) . ', kreeg ' . json_encode( $r[ $k ] ?? null ) . ' (' . json_encode( $r, JSON_UNESCAPED_UNICODE ) . ')' );
	}
	if ( 'ai_edit' === ( $r['route'] ?? '' ) ) {
		$check( $id, false !== strpos( $r['prompt'], 'KEEP EXACTLY THE SAME' ), 'edit-prompt mist behoudlijst' );
		$check( $id, false !== strpos( $r['prompt'], 'no gradients' ), 'edit-prompt mist productieregels' );
	}
}

// History is repeated and capped.
$h = array();
foreach ( array( 'maak de wolf bozer', 'voeg vlammen toe', 'haal de bijl weg', 'maak de vlammen groter' ) as $i => $instr ) {
	$r = E::refine( $briefs['T01'], $instr, $cfg, $h );
	if ( $i > 0 ) {
		$check( 'H', false !== strpos( $r['prompt'], 'Earlier accepted changes' ), 'geschiedenis niet herhaald' );
	}
	$h = $r['history'];
}
$check( 'H', count( $h ) === 3, 'geschiedenis niet op 3 begrensd' );

// Spelling helper.
$check( 'SP', 'W-O-L-F / M-C' === E::spell( 'WOLF MC' ), 'spell()' );

echo ( $fail ? "FAILED {$fail}" : 'OK' ) . " — prompt-engine: {$pass} checks\n";
exit( $fail ? 1 : 0 );
