<?php
/**
 * Admin confirmation screen: send one proof e-mail for all patches of an order (atelier-flow-10).
 * Served by admin-post.php?action=bps_send_order_proof (capability edit_shop_orders + nonce).
 *
 * @package BorduurPatchStudio
 * @var \WC_Order                $order
 * @var \WC_Order_Item_Product[] $lines Lines that get a new round.
 * @var string                   $back  Order edit URL.
 * @var string                   $nonce Nonce.
 */

use BorduurPatchStudio\Proof\Proof_Service;

defined( 'ABSPATH' ) || exit;

/* translators: %s: order number */
$bps_title = sprintf( __( 'Proef voor hele bestelling #%s versturen', 'borduur-patch-studio' ), $order->get_order_number() );
$bps_first = trim( (string) $order->get_billing_first_name() );
?><!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $bps_title ); ?></title>
<style>
	body { margin: 0; background: #F0F0F1; color: #1D2327; font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
	main { max-width: 720px; margin: 32px auto; background: #fff; border: 1px solid #DCDCDE; border-radius: 8px; padding: 24px; }
	h1 { font-size: 22px; margin: 0 0 12px; }
	ul { padding-left: 1.2em; }
	label { display: block; font-weight: 600; margin: 12px 0 4px; }
	input[type=email], textarea { width: 100%; box-sizing: border-box; padding: 8px; font: inherit; border: 1px solid #8C8F94; border-radius: 4px; }
	.actions { display: flex; gap: 12px; margin-top: 16px; align-items: center; }
	button { font: inherit; font-weight: 600; background: #2271B1; color: #fff; border: 0; border-radius: 4px; padding: 10px 18px; min-height: 44px; cursor: pointer; }
	a { color: #2271B1; }
	button:focus-visible, a:focus-visible, input:focus-visible, textarea:focus-visible { outline: 3px solid #5926C5; outline-offset: 2px; }
</style>
</head>
<body>
<main>
	<h1><?php echo esc_html( $bps_title ); ?></h1>
	<?php if ( ! $lines ) : ?>
		<p><?php esc_html_e( 'Er is geen patch in deze bestelling waarvoor nog een proef nodig is.', 'borduur-patch-studio' ); ?></p>
		<p><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Terug naar de bestelling', 'borduur-patch-studio' ); ?></a></p>
	<?php else : ?>
		<p><?php esc_html_e( 'De klant krijgt één e-mail met de proef van deze patches en één pagina om per patch goed te keuren of een aanpassing te vragen, of alles in één keer goed te keuren. Als proefbeeld gebruiken we het voorbeeld uit de ontwerptool; wil je een eigen beeld, stuur die patch dan apart vanuit de fiche.', 'borduur-patch-studio' ); ?></p>
		<ul>
			<?php foreach ( $lines as $bps_item ) : ?>
				<li>
					<?php
					$bps_round = Proof_Service::get( $bps_item )['round'] + 1;
					/* translators: 1: design ref, 2: round */
					echo esc_html( sprintf( __( '%1$s — wordt proefronde %2$d', 'borduur-patch-studio' ), (string) $bps_item->get_meta( 'bps_ref' ), $bps_round ) );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bps_send_order_proof">
			<input type="hidden" name="order" value="<?php echo (int) $order->get_id(); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<label for="bps-op-to"><?php esc_html_e( 'Ontvanger', 'borduur-patch-studio' ); ?></label>
			<input type="email" id="bps-op-to" name="recipient" required value="<?php echo esc_attr( (string) $order->get_billing_email() ); ?>">
			<label for="bps-op-msg"><?php esc_html_e( 'Bericht aan de klant (optioneel)', 'borduur-patch-studio' ); ?></label>
			<textarea id="bps-op-msg" name="message" rows="5" maxlength="2000"><?php echo esc_textarea( '' !== $bps_first ? sprintf( /* translators: %s: first name */ __( 'Hallo %s, hier is de digitale proef van je patches. Kijk je alles even na?', 'borduur-patch-studio' ), $bps_first ) : '' ); ?></textarea>
			<div class="actions">
				<button type="submit" data-bps-order-proof-send><?php esc_html_e( 'Proef versturen', 'borduur-patch-studio' ); ?></button>
				<a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Annuleren', 'borduur-patch-studio' ); ?></a>
			</div>
		</form>
	<?php endif; ?>
</main>
</body>
</html>
