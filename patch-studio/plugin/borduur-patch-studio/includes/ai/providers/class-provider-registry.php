<?php
/**
 * Provider registry: primary / fallback / vectorizer per settings; mode "test" always uses the mock.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

use BorduurPatchStudio\AI\AI_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §6.5. The filter `bps_ai_providers` (id => class name) lets Ideogram/Gemini join later without
 * core changes; classes must implement Provider.
 */
final class Provider_Registry {

	const BUILTIN = array(
		'mock'    => Mock_Provider::class,
		'openai'  => Openai_Provider::class,
		'recraft' => Recraft_Provider::class,
	);

	/** @var array<string, Provider> */
	private static $instances = array();

	/** @var array<string, Provider> Test overrides. */
	private static $overrides = array();

	/**
	 * Registered id => class.
	 *
	 * @return array<string,string>
	 */
	public static function classes(): array {
		$list = function_exists( 'apply_filters' ) ? apply_filters( 'bps_ai_providers', self::BUILTIN ) : self::BUILTIN;
		$out  = array();
		foreach ( is_array( $list ) ? $list : self::BUILTIN as $id => $class ) {
			if ( is_string( $id ) && preg_match( '/^[a-z0-9_]{2,16}$/', $id ) && is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, Provider::class ) ) {
				$out[ $id ] = $class;
			}
		}
		$out['mock'] = Mock_Provider::class;
		return $out;
	}

	/**
	 * Provider by id, or null.
	 *
	 * @param string $id Id.
	 */
	public static function get( string $id ): ?Provider {
		if ( isset( self::$overrides[ $id ] ) ) {
			return self::$overrides[ $id ];
		}
		if ( ! isset( self::$instances[ $id ] ) ) {
			$classes = self::classes();
			if ( ! isset( $classes[ $id ] ) ) {
				return null;
			}
			self::$instances[ $id ] = new $classes[ $id ]();
		}
		return self::$instances[ $id ];
	}

	/**
	 * Primary provider. Test mode → mock. A primary without key is still returned (the runner reports
	 * "not configured" as a failed job with refund; the admin status shows it).
	 */
	public static function primary(): Provider {
		if ( 'test' === AI_Settings::mode() ) {
			return self::get( 'mock' );
		}
		$p = self::get( (string) AI_Settings::path( 'default', 'mock' ) );
		return $p ? $p : self::get( 'mock' );
	}

	/**
	 * Fallback provider for retryable failures, only when configured and different from the primary.
	 */
	public static function fallback(): ?Provider {
		if ( 'test' === AI_Settings::mode() ) {
			return null;
		}
		$id = (string) AI_Settings::path( 'fallback', '' );
		$p  = '' !== $id ? self::get( $id ) : null;
		if ( ! $p || $p->id() === self::primary()->id() || ! $p->is_configured() ) {
			return null;
		}
		return $p;
	}

	/**
	 * Vectorizer for the chosen proposal, only when configured.
	 */
	public static function vectorizer(): ?Provider {
		if ( 'test' === AI_Settings::mode() ) {
			return null;
		}
		$id = (string) AI_Settings::path( 'vectorizer', '' );
		$p  = '' !== $id ? self::get( $id ) : null;
		return ( $p && $p->is_configured() && ! empty( $p->capabilities()['vector_output'] ) ) ? $p : null;
	}

	/**
	 * Model id configured for a provider.
	 *
	 * @param Provider $p Provider.
	 */
	public static function model( Provider $p ): string {
		return (string) AI_Settings::path( 'providers.' . $p->id() . '.model', 'mock' === $p->id() ? 'mock-1' : '' );
	}

	/**
	 * Replace a provider (tests) or reset all (null id).
	 *
	 * @param string|null   $id Id.
	 * @param Provider|null $p  Provider.
	 */
	public static function override( ?string $id, ?Provider $p = null ): void {
		if ( null === $id ) {
			self::$overrides = array();
			self::$instances = array();
			return;
		}
		if ( null === $p ) {
			unset( self::$overrides[ $id ] );
		} else {
			self::$overrides[ $id ] = $p;
		}
	}
}
