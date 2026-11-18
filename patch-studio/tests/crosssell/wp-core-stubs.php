<?php
/**
 * Minimal core stand-ins for tests/crosssell/run-wp.php: Settings (live config = config/defaults.json, as the
 * real Settings returns without overrides) and a Logger that records what it would write.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

namespace BorduurPatchStudio;

if ( ! class_exists( Settings::class, false ) ) {
	final class Settings {
		public static function get(): array {
			static $cfg = null;
			if ( null === $cfg ) {
				$cfg = json_decode( (string) file_get_contents( BPS_DIR . 'config/defaults.json' ), true );
			}
			return $cfg;
		}
		public static function product_id(): int {
			return 0;
		}
	}
}

if ( ! class_exists( Logger::class, false ) ) {
	final class Logger {
		public static $lines = array();
		public static function __callStatic( $level, $args ) {
			self::$lines[] = array( $level, $args[0] ?? '', $args[1] ?? array() );
			return true;
		}
	}
}
