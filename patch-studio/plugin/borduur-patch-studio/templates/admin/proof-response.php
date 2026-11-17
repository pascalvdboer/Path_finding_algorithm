<?php
/**
 * Customer page behind the links in the digital proof e-mail (noindex, never cached).
 * GET shows a confirmation; only the POST of this form changes the proof status.
 *
 * @package BorduurPatchStudio
 * @var string          $view      confirm|done|answered|superseded|invalid
 * @var string          $action    approve|changes
 * @var string          $error     Validation message.
 * @var string          $lang      nl|fr
 * @var array|null      $design    DesignRecord.
 * @var array|null      $proof     Normalized proof.
 * @var \WC_Order|null  $order
 * @var array           $rows      Customer summary rows.
 * @var array           $form      {url, fields}.
 * @var string          $other_url Link to the other answer.
 * @var array           $contact   config.contact.
 * @var string          $note      Posted note (on validation error).
 * @var bool            $use_theme Wrap in the theme header/footer.
 */

use BorduurPatchStudio\Admin\Order_Production as BPS_OP;

defined( 'ABSPATH' ) || exit;

$bps_ref   = $design ? (string) $design['ref'] : '';
$bps_img   = $proof && '' !== $proof['image_url'] ? $proof['image_url'] : ( $design ? (string) $design['preview_url'] : '' );
$bps_onum  = $order ? (string) $order->get_order_number() : '';
$bps_phone = (string) ( $contact['phone'] ?? '' );
$bps_mail  = (string) ( $contact['email'] ?? '' );
$bps_wa    = preg_replace( '/\D+/', '', (string) ( $contact['whatsapp'] ?? '' ) );

if ( 'confirm' === $view ) {
	$bps_title = 'changes' === $action ? BPS_OP::l( __( 'Aanpassing vragen', 'borduur-patch-studio' ) ) : BPS_OP::l( __( 'Proef goedkeuren', 'borduur-patch-studio' ) );
} elseif ( 'done' === $view ) {
	$bps_title = 'changes' === $action ? BPS_OP::l( __( 'Bedankt, we hebben je opmerking ontvangen', 'borduur-patch-studio' ) ) : BPS_OP::l( __( 'Bedankt, je proef is goedgekeurd', 'borduur-patch-studio' ) );
} elseif ( 'answered' === $view ) {
	$bps_title = BPS_OP::l( __( 'Je reactie op de digitale proef', 'borduur-patch-studio' ) );
} elseif ( 'superseded' === $view ) {
	$bps_title = BPS_OP::l( __( 'Er is intussen een nieuwere proef verstuurd', 'borduur-patch-studio' ) );
} else {
	$bps_title = BPS_OP::l( __( 'Deze link werkt niet (meer)', 'borduur-patch-studio' ) );
}

if ( $use_theme ) {
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_filter(
		'pre_get_document_title',
		static function () use ( $bps_title ) {
			return $bps_title;
		}
	);
	get_header();
} else {
	?><!doctype html>
<html lang="<?php echo esc_attr( 'fr' === $lang ? 'fr-BE' : 'nl-BE' ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $bps_title . ' · ' . get_bloginfo( 'name' ) ); ?></title>
</head>
<body>
	<?php
}
?>
<style>
	.bps-proofpage { --bps-cta:#0B74A6; --bps-navy:#0A1F44; --bps-blue:#21A9E2; --bps-ink:#1E1B1B; --bps-muted:#6B6B6B; --bps-line:#E3EBF2;
		box-sizing: border-box; max-width: 760px; margin: 32px auto 48px; padding: 0 16px; color: var(--bps-ink); font-family: "Assistant", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; font-size: 17px; line-height: 1.55; }
	.bps-proofpage *, .bps-proofpage *::before, .bps-proofpage *::after { box-sizing: inherit; }
	.bps-proofpage h1 { font-family: "Sora", system-ui, sans-serif; font-weight: 700; color: var(--bps-navy); font-size: clamp(24px, 4vw, 32px); line-height: 1.2; margin: 0 0 8px; text-transform: none; letter-spacing: 0; }
	.bps-proofpage .bps-pp__sub { color: var(--bps-muted); margin: 0 0 24px; }
	.bps-proofpage .bps-pp__card { background: #fff; border: 1px solid var(--bps-line); border-radius: 12px; padding: 24px; box-shadow: 0 4px 24px rgba(10,31,68,.06); }
	.bps-proofpage .bps-pp__img { display: block; margin: 0 auto 20px; max-width: min(100%, 420px); height: auto; border-radius: 8px; background: #F5F8FB; }
	.bps-proofpage table { width: 100%; border-collapse: collapse; margin: 0 0 20px; font-size: 15px; }
	.bps-proofpage th, .bps-proofpage td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--bps-line); vertical-align: top; }
	.bps-proofpage th { width: 38%; font-weight: 600; color: var(--bps-navy); }
	.bps-proofpage .bps-pp__sw { display: inline-block; width: 14px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,.25); vertical-align: -2px; margin-right: 6px; }
	.bps-proofpage label { display: block; font-weight: 700; color: var(--bps-navy); margin: 0 0 6px; }
	.bps-proofpage textarea { width: 100%; min-height: 120px; padding: 12px; font: inherit; border: 1px solid #8A8A8A; border-radius: 8px; background: #fff; color: var(--bps-ink); }
	.bps-proofpage .bps-pp__help { color: var(--bps-muted); font-size: 15px; margin: 6px 0 16px; }
	.bps-proofpage .bps-pp__btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 12px 24px; border: 0; border-radius: 8px; background: var(--bps-cta); color: #fff; font: 700 17px/1.2 "Sora", system-ui, sans-serif; text-decoration: none; cursor: pointer; width: 100%; }
	.bps-proofpage .bps-pp__btn:hover { background: var(--bps-navy); color: #fff; }
	.bps-proofpage a:focus-visible, .bps-proofpage button:focus-visible, .bps-proofpage textarea:focus-visible { outline: 3px solid #5926C5; outline-offset: 2px; }
	.bps-proofpage .bps-pp__alt { text-align: center; margin: 16px 0 0; }
	.bps-proofpage .bps-pp__alt a, .bps-proofpage .bps-pp__contact a { color: var(--bps-cta); }
	.bps-proofpage .bps-pp__error { border: 1px solid #B42318; background: #FEF3F2; color: #B42318; border-radius: 8px; padding: 10px 14px; margin: 0 0 16px; }
	.bps-proofpage .bps-pp__ok { border-left: 4px solid #15803D; background: #F0FAF3; padding: 14px 16px; border-radius: 8px; margin: 0 0 20px; }
	.bps-proofpage .bps-pp__info { border-left: 4px solid var(--bps-blue); background: #F7FBFE; padding: 14px 16px; border-radius: 8px; margin: 0 0 20px; }
	.bps-proofpage blockquote { margin: 0 0 20px; padding: 12px 16px; border-left: 4px solid var(--bps-line); background: #FAFBFC; white-space: pre-line; }
	.bps-proofpage .bps-pp__contact { color: var(--bps-muted); font-size: 15px; margin: 20px 0 0; text-align: center; }
</style>
<main class="bps-proofpage" id="bps-proof">
	<h1><?php echo esc_html( $bps_title ); ?></h1>
	<?php if ( '' !== $bps_ref ) : ?>
		<p class="bps-pp__sub">
			<?php
			/* translators: %s: design ref */
			echo esc_html( sprintf( BPS_OP::l( __( 'Patch %s', 'borduur-patch-studio' ) ), $bps_ref ) );
			if ( '' !== $bps_onum ) {
				/* translators: %s: order number */
				echo esc_html( ' · ' . sprintf( BPS_OP::l( __( 'Bestelling #%s', 'borduur-patch-studio' ) ), $bps_onum ) );
			}
			if ( $proof && $proof['round'] > 1 ) {
				/* translators: %d: round */
				echo esc_html( ' · ' . sprintf( BPS_OP::l( __( 'Proefronde %d', 'borduur-patch-studio' ) ), (int) $proof['round'] ) );
			}
			?>
		</p>
	<?php endif; ?>

	<div class="bps-pp__card">
		<?php if ( 'confirm' === $view ) : ?>
			<?php if ( '' !== $bps_img ) : ?>
				<img class="bps-pp__img" src="<?php echo esc_url( $bps_img ); ?>" alt="<?php echo esc_attr( BPS_OP::l( __( 'Digitale proef van je patch', 'borduur-patch-studio' ) ) ); ?>">
			<?php endif; ?>
			<?php if ( $rows ) : ?>
				<table>
					<?php foreach ( $rows as $bps_row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $bps_row['label'] ); ?></th>
							<td>
								<?php if ( ! empty( $bps_row['hex'] ) ) : ?>
									<span class="bps-pp__sw" style="background:<?php echo esc_attr( $bps_row['hex'] ); ?>" aria-hidden="true"></span>
								<?php endif; ?>
								<?php echo esc_html( $bps_row['value'] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
			<p class="bps-pp__info"><?php echo esc_html( BPS_OP::l( __( 'Bekijk je proef en bevestig hieronder. Pas na jouw akkoord starten we de productie.', 'borduur-patch-studio' ) ) ); ?></p>
			<?php if ( '' !== $error ) : ?>
				<p class="bps-pp__error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $form['url'] ); ?>">
				<?php foreach ( $form['fields'] as $bps_k => $bps_v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $bps_k ); ?>" value="<?php echo esc_attr( (string) $bps_v ); ?>">
				<?php endforeach; ?>
				<?php if ( 'changes' === $action ) : ?>
					<label for="bps-note"><?php echo esc_html( BPS_OP::l( __( 'Wat wil je aanpassen?', 'borduur-patch-studio' ) ) ); ?></label>
					<textarea id="bps-note" name="note" required maxlength="2000" aria-describedby="bps-note-help"<?php echo '' !== $error ? ' aria-invalid="true"' : ''; ?>><?php echo esc_textarea( $note ); ?></textarea>
					<p class="bps-pp__help" id="bps-note-help"><?php echo esc_html( BPS_OP::l( __( 'Beschrijf zo concreet mogelijk wat er anders moet, bijvoorbeeld een kleur, de maat of een tekst.', 'borduur-patch-studio' ) ) ); ?></p>
					<button type="submit" class="bps-pp__btn"><?php echo esc_html( BPS_OP::l( __( 'Verstuur mijn opmerking', 'borduur-patch-studio' ) ) ); ?></button>
				<?php else : ?>
					<label for="bps-note"><?php echo esc_html( BPS_OP::l( __( 'Opmerking (optioneel)', 'borduur-patch-studio' ) ) ); ?></label>
					<textarea id="bps-note" name="note" maxlength="2000" rows="3" style="min-height:80px"><?php echo esc_textarea( $note ); ?></textarea>
					<p class="bps-pp__help"></p>
					<button type="submit" class="bps-pp__btn"><?php echo esc_html( BPS_OP::l( __( 'Ja, ik keur deze proef goed', 'borduur-patch-studio' ) ) ); ?></button>
				<?php endif; ?>
			</form>
			<?php if ( '' !== $other_url ) : ?>
				<p class="bps-pp__alt">
					<a href="<?php echo esc_url( $other_url ); ?>">
						<?php echo esc_html( 'changes' === $action ? BPS_OP::l( __( 'Toch goedkeuren', 'borduur-patch-studio' ) ) : BPS_OP::l( __( 'Toch iets aanpassen', 'borduur-patch-studio' ) ) ); ?>
					</a>
				</p>
			<?php endif; ?>

		<?php elseif ( 'done' === $view ) : ?>
			<?php if ( '' !== $bps_img ) : ?>
				<img class="bps-pp__img" src="<?php echo esc_url( $bps_img ); ?>" alt="<?php echo esc_attr( BPS_OP::l( __( 'Digitale proef van je patch', 'borduur-patch-studio' ) ) ); ?>" style="max-width:240px">
			<?php endif; ?>
			<p class="<?php echo 'changes' === $action ? 'bps-pp__info' : 'bps-pp__ok'; ?>" role="status">
				<?php
				echo esc_html(
					'changes' === $action
						? BPS_OP::l( __( 'We passen je ontwerp aan en sturen je een nieuwe digitale proef.', 'borduur-patch-studio' ) )
						: BPS_OP::l( __( 'We starten de productie van je patch. Je hoort van ons zodra je bestelling onderweg is.', 'borduur-patch-studio' ) )
				);
				?>
			</p>
			<?php if ( $proof && '' !== $proof['note'] ) : ?>
				<p><strong><?php echo esc_html( BPS_OP::l( __( 'Je opmerking', 'borduur-patch-studio' ) ) ); ?></strong></p>
				<blockquote><?php echo esc_html( $proof['note'] ); ?></blockquote>
			<?php endif; ?>

		<?php elseif ( 'answered' === $view ) : ?>
			<?php $bps_when = $proof ? BPS_OP::date_text( (string) $proof['responded_at'] ) : ''; ?>
			<p class="<?php echo ( $proof && 'approved' === $proof['status'] ) ? 'bps-pp__ok' : 'bps-pp__info'; ?>">
				<?php
				if ( $proof && 'approved' === $proof['status'] ) {
					/* translators: %s: date */
					echo esc_html( sprintf( BPS_OP::l( __( 'Je hebt deze proef al goedgekeurd op %s.', 'borduur-patch-studio' ) ), $bps_when ) );
				} else {
					/* translators: %s: date */
					echo esc_html( sprintf( BPS_OP::l( __( 'Je vroeg op %s een aanpassing. We sturen je een nieuwe proef.', 'borduur-patch-studio' ) ), $bps_when ) );
				}
				?>
			</p>
			<p><?php echo esc_html( BPS_OP::l( __( 'Wil je toch nog iets wijzigen? Neem dan contact met ons op.', 'borduur-patch-studio' ) ) ); ?></p>

		<?php elseif ( 'superseded' === $view ) : ?>
			<p class="bps-pp__info"><?php echo esc_html( BPS_OP::l( __( 'Gebruik de knoppen in de laatste e-mail met je digitale proef.', 'borduur-patch-studio' ) ) ); ?></p>

		<?php else : ?>
			<p class="bps-pp__info"><?php echo esc_html( BPS_OP::l( __( 'Controleer of je de volledige link uit de e-mail gebruikt, of neem contact met ons op.', 'borduur-patch-studio' ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $bps_phone || '' !== $bps_mail ) : ?>
			<p class="bps-pp__contact">
				<?php echo esc_html( BPS_OP::l( __( 'Contact', 'borduur-patch-studio' ) ) ); ?>:
				<?php if ( '' !== $bps_phone ) : ?>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $bps_phone ) ); ?>"><?php echo esc_html( $bps_phone ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $bps_wa ) : ?>
					· <a href="<?php echo esc_url( 'https://wa.me/' . $bps_wa ); ?>" rel="noopener">WhatsApp</a>
				<?php endif; ?>
				<?php if ( '' !== $bps_mail ) : ?>
					· <a href="<?php echo esc_url( 'mailto:' . $bps_mail ); ?>"><?php echo esc_html( $bps_mail ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php if ( ! $use_theme ) : ?>
		<p class="bps-pp__alt"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( BPS_OP::l( __( 'Terug naar de website', 'borduur-patch-studio' ) ) ); ?></a></p>
	<?php endif; ?>
</main>
<?php
if ( $use_theme ) {
	get_footer();
} else {
	echo "</body>\n</html>\n";
}
