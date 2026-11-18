<?php
/**
 * Licence backend driver contract.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * One licence backend (today: the own WooCommerce store). Client talks only to this interface, so the backend can be
 * chosen when a sale is actually decided without touching the update logic.
 *
 * Every method returns plain data (never code) or a WP_Error. Implementations must:
 * - only talk HTTPS to the fixed update host (Client::update_url()), with sslverify on and a 10 s timeout;
 * - send only the licence key, the site URL, the plugin slug and the installed version.
 */
interface Driver {

	/**
	 * Activate a key for this site.
	 *
	 * @param string $key  Licence key.
	 * @param string $site Site URL.
	 * @return array{status:string, expires?:string, message?:string}|\WP_Error
	 */
	public function activate( string $key, string $site );

	/**
	 * Release the key for this site.
	 *
	 * @param string $key  Licence key.
	 * @param string $site Site URL.
	 * @return array{status:string, message?:string}|\WP_Error
	 */
	public function deactivate( string $key, string $site );

	/**
	 * Current status of the key (valid, expired, invalid, inactive, ...).
	 *
	 * @param string $key  Licence key.
	 * @param string $site Site URL.
	 * @return array{status:string, expires?:string, message?:string}|\WP_Error
	 */
	public function check( string $key, string $site );

	/**
	 * Latest release for this key: version, package URL, Ed25519 signature of the zip, changelog.
	 * Null when there is no update available for this key (e.g. expired).
	 *
	 * @param string $key     Licence key.
	 * @param string $site    Site URL.
	 * @param string $version Installed version.
	 * @return array<string, mixed>|null|\WP_Error
	 */
	public function update_info( string $key, string $site, string $version );
}
