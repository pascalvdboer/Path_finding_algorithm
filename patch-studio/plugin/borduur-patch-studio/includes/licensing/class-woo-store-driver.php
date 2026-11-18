<?php
/**
 * Licence driver for the own WooCommerce store (update host = BPS_UPDATE_URL).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Talks JSON to `{update host}/wp-json/bps-license/v1/{activate|deactivate|check|update}` with POST.
 * The server side is not part of this plugin; its contract is documented in docs/premium/license-client-security.md.
 * No WooCommerce REST keys are ever shipped: the licence key itself is the only credential.
 */
final class Woo_Store_Driver implements Driver {

	/** REST namespace on the update host. */
	const ROUTE = 'wp-json/bps-license/v1/';

	/** Request timeout in seconds (security-12 #6). */
	const TIMEOUT = 10;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key  Key.
	 * @param string $site Site.
	 */
	public function activate( string $key, string $site ) {
		return $this->call( 'activate', $key, $site );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key  Key.
	 * @param string $site Site.
	 */
	public function deactivate( string $key, string $site ) {
		return $this->call( 'deactivate', $key, $site );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key  Key.
	 * @param string $site Site.
	 */
	public function check( string $key, string $site ) {
		return $this->call( 'check', $key, $site );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key     Key.
	 * @param string $site    Site.
	 * @param string $version Installed version.
	 */
	public function update_info( string $key, string $site, string $version ) {
		$res = $this->call( 'update', $key, $site, array( 'version' => $version ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return empty( $res['version'] ) ? null : $res;
	}

	/**
	 * POST one request and decode the JSON answer (data only).
	 *
	 * @param string $action Endpoint.
	 * @param string $key    Key.
	 * @param string $site   Site.
	 * @param array  $extra  Extra body fields.
	 * @return array|\WP_Error
	 */
	private function call( string $action, string $key, string $site, array $extra = array() ) {
		$base = Client::update_url();
		if ( '' === $base ) {
			return new \WP_Error( 'bps_license_host', 'Update host is not an HTTPS URL.' );
		}
		$response = wp_safe_remote_post(
			$base . self::ROUTE . $action,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array_merge(
					array(
						'key'  => $key,
						'site' => $site,
						'slug' => Client::SLUG,
					),
					$extra
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new \WP_Error( 'bps_license_http', 'Licence server answered HTTP ' . $code . '.', array( 'status' => $code ) );
		}
		return $body;
	}
}
