<?php
/**
 * "Licentie & updates" admin screen (only when licensing is enabled).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden unless the constant BPS_LICENSING is true or a key is already stored (Client::ui_enabled()), so the
 * owner's own shop sees nothing. The key is shown masked, changed only with manage_options + nonce, and never sent
 * to the browser in full.
 */
final class Admin {

	/** Page slug. */
	const SLUG = 'bps-license';

	/** admin-post action / nonce. */
	const ACTION = 'bps_license';

	/** Capability. */
	const CAP = 'manage_options';

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks (admin only, only when licensing is enabled).
	 */
	public static function register(): void {
		if ( self::$registered || ! Client::ui_enabled() ) {
			return;
		}
		self::$registered = true;
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
	}

	/**
	 * Parent menu: the Patch Studio menu (contract C7) when present, else WooCommerce or Settings.
	 */
	public static function parent_slug(): string {
		if ( class_exists( 'BorduurPatchStudio\Admin\Menu' ) && defined( 'BorduurPatchStudio\Admin\Menu::SLUG' ) ) {
			return (string) constant( 'BorduurPatchStudio\Admin\Menu::SLUG' );
		}
		return class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
	}

	/**
	 * Submenu page.
	 */
	public static function menu(): void {
		add_submenu_page(
			self::parent_slug(),
			__( 'Patch Studio: licentie en updates', 'borduur-patch-studio' ),
			__( 'Licentie en updates', 'borduur-patch-studio' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		$parent = self::parent_slug();
		$base   = false !== strpos( $parent, '.php' ) ? $parent : 'admin.php';
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( $base ) );
	}

	/**
	 * Privacy policy suggestion: what the licence check sends (security-12 #7).
	 */
	public static function privacy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! Client::has_key() ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Patch Studio (licentie)',
			'<p>' . esc_html__( 'Om updates te controleren stuurt Patch Studio de licentiesleutel, het adres van deze website, de pluginnaam en de geïnstalleerde versie naar de updateserver. Er worden geen gegevens van klanten of bestellingen verstuurd.', 'borduur-patch-studio' ) . '</p>'
		);
	}

	/**
	 * Save / remove / check (admin-post).
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'borduur-patch-studio' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$do     = isset( $_POST['bps_do'] ) ? sanitize_key( wp_unslash( $_POST['bps_do'] ) ) : '';
		$notice = 'saved';
		if ( 'remove' === $do ) {
			Client::remove_key();
			$notice = 'removed';
		} elseif ( 'check' === $do ) {
			$res    = Client::check_now();
			$notice = is_wp_error( $res ) ? 'unreachable' : 'checked';
		} else {
			$key = isset( $_POST['bps_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bps_license_key'] ) ) : '';
			$res = Client::save_key( $key );
			if ( is_wp_error( $res ) ) {
				$notice = 'bps_license_format' === $res->get_error_code() ? 'format' : 'unreachable';
			}
		}
		wp_safe_redirect( self::url( array( 'bps_notice' => $notice ) ) );
		exit;
	}

	/**
	 * Human label of a status.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'valid':
				return __( 'Actief', 'borduur-patch-studio' );
			case 'expired':
				return __( 'Verlopen: de winkel werkt gewoon verder, alleen updates staan stil.', 'borduur-patch-studio' );
			case 'inactive':
			case 'site_limit':
				return __( 'Niet geactiveerd voor deze website.', 'borduur-patch-studio' );
			case 'unchecked':
				return __( 'Opgeslagen, maar de updateserver was niet bereikbaar. Probeer later "Nu controleren".', 'borduur-patch-studio' );
			case '':
				return __( 'Geen licentie', 'borduur-patch-studio' );
		}
		return __( 'Ongeldig', 'borduur-patch-studio' );
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$d       = Client::data();
		$notice  = isset( $_GET['bps_notice'] ) ? sanitize_key( wp_unslash( $_GET['bps_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notices = array(
			'saved'       => array( 'success', __( 'De licentie is opgeslagen.', 'borduur-patch-studio' ) ),
			'removed'     => array( 'success', __( 'De licentie is verwijderd. Patch Studio werkt gewoon verder zonder automatische updates.', 'borduur-patch-studio' ) ),
			'checked'     => array( 'success', __( 'De status is bijgewerkt.', 'borduur-patch-studio' ) ),
			'format'      => array( 'error', __( 'Dit lijkt geen geldige licentiesleutel. Kopieer ze opnieuw uit je bestelling.', 'borduur-patch-studio' ) ),
			'unreachable' => array( 'warning', __( 'De updateserver was niet bereikbaar. Je sleutel is bewaard; probeer later opnieuw.', 'borduur-patch-studio' ) ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Licentie en updates', 'borduur-patch-studio' ) . '</h1>';
		if ( isset( $notices[ $notice ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $notices[ $notice ][0] ) . '"><p>' . esc_html( $notices[ $notice ][1] ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Met een licentie krijg je automatische, ondertekende updates. Zonder licentie werkt Patch Studio volledig; er wordt dan niets naar de updateserver gestuurd.', 'borduur-patch-studio' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Status', 'borduur-patch-studio' ) . '</th><td>' . esc_html( self::status_label( $d['status'] ) );
		if ( '' !== $d['expires'] ) {
			/* translators: %s: expiry date (YYYY-MM-DD) */
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Geldig tot %s', 'borduur-patch-studio' ), $d['expires'] ) ) . '</span>';
		}
		echo '</td></tr>';
		if ( '' !== $d['key'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Sleutel', 'borduur-patch-studio' ) . '</th><td><code>' . esc_html( Client::masked_key() ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<p><label for="bps-license-key">' . esc_html( '' === $d['key'] ? __( 'Licentiesleutel', 'borduur-patch-studio' ) : __( 'Nieuwe licentiesleutel', 'borduur-patch-studio' ) ) . '</label><br>';
		echo '<input type="password" id="bps-license-key" name="bps_license_key" class="regular-text" autocomplete="off" spellcheck="false" value=""></p>';
		echo '<p><button type="submit" class="button button-primary" name="bps_do" value="save">' . esc_html__( 'Licentie opslaan', 'borduur-patch-studio' ) . '</button> ';
		if ( '' !== $d['key'] ) {
			echo '<button type="submit" class="button" name="bps_do" value="check">' . esc_html__( 'Nu controleren', 'borduur-patch-studio' ) . '</button> ';
			echo '<button type="submit" class="button button-link-delete" name="bps_do" value="remove">' . esc_html__( 'Licentie verwijderen', 'borduur-patch-studio' ) . '</button>';
		}
		echo '</p></form></div>';
	}
}
