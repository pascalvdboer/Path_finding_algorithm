<?php
/**
 * Customer page behind the links in the digital proof e-mail (noindex, never cached, no referrer).
 * GET shows a page; only the POST of the form changes anything.
 *
 * @package BorduurPatchStudio
 * @var string                      $view       confirm|done|answered|superseded|invalid|stopped|cancel_confirm|cancel_done|cancel_requested
 * @var string                      $action     approve|changes|cancel
 * @var string                      $error      Validation message.
 * @var string                      $lang       nl|fr
 * @var array|null                  $design     DesignRecord.
 * @var array|null                  $proof      Normalized proof of the order line.
 * @var \WC_Order|null              $order
 * @var \WC_Order_Item_Product|null $item
 * @var array                       $rows       Customer summary rows.
 * @var array                       $form       {url, fields}.
 * @var string                      $other_url  Link to the other answer.
 * @var string                      $cancel_url Cancel link (promises.cancelUntilProof) or ''.
 * @var string                      $pay_url    Pay now (promises.payAfterProof) or ''.
 * @var string                      $img        Image to show.
 * @var string                      $full_url   Full resolution image.
 * @var string                      $prev_url   Image of the previous round when it differs.
 * @var array                       $contact    config.contact.
 * @var string                      $note       Posted note (on validation error).
 * @var bool                        $use_theme  Wrap in the theme header/footer.
 */

use BorduurPatchStudio\Admin\Order_Production as BPS_OP;

defined( 'ABSPATH' ) || exit;

$bps_ref  = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta( 'bps_ref' ) : '';
$bps_ref  = '' !== $bps_ref ? $bps_ref : ( $design ? (string) $design['ref'] : '' );
$bps_onum = $order ? (string) $order->get_order_number() : '';
/* translators: %s: design ref */
$bps_alt = sprintf( __( 'Digitale proef van patch %s', 'borduur-patch-studio' ), $bps_ref );

$bps_titles = array(
	'confirm'          => 'changes' === $action ? __( 'Aanpassing vragen', 'borduur-patch-studio' ) : __( 'Proef goedkeuren', 'borduur-patch-studio' ),
	'done'             => 'changes' === $action ? __( 'Bedankt, we hebben je opmerking ontvangen', 'borduur-patch-studio' ) : __( 'Bedankt, je proef is goedgekeurd', 'borduur-patch-studio' ),
	'answered'         => __( 'Je reactie op de digitale proef', 'borduur-patch-studio' ),
	'superseded'       => __( 'Er is intussen een nieuwere proef verstuurd', 'borduur-patch-studio' ),
	'stopped'          => __( 'Deze bestelling is geannuleerd', 'borduur-patch-studio' ),
	'cancel_confirm'   => __( 'Bestelling annuleren?', 'borduur-patch-studio' ),
	'cancel_done'      => __( 'Je bestelling is geannuleerd', 'borduur-patch-studio' ),
	'cancel_requested' => __( 'We hebben je vraag om te annuleren ontvangen', 'borduur-patch-studio' ),
);
$bps_title  = $bps_titles[ $view ] ?? __( 'Deze link werkt niet (meer)', 'borduur-patch-studio' );

BPS_OP::template(
	'admin/proof-page-open.php',
	array(
		'bps_title' => $bps_title,
		'lang'      => $lang,
		'use_theme' => $use_theme,
	)
);
?>
	<?php if ( '' !== $bps_ref ) : ?>
		<p class="bps-pp__sub">
			<?php
			/* translators: %s: design ref */
			echo esc_html( sprintf( __( 'Patch %s', 'borduur-patch-studio' ), $bps_ref ) );
			if ( '' !== $bps_onum ) {
				/* translators: %s: order number */
				echo esc_html( ' · ' . sprintf( __( 'Bestelling #%s', 'borduur-patch-studio' ), $bps_onum ) );
			}
			if ( $proof && $proof['round'] > 1 ) {
				/* translators: %d: round */
				echo esc_html( ' · ' . sprintf( __( 'Proefronde %d', 'borduur-patch-studio' ), (int) $proof['round'] ) );
			}
			?>
		</p>
	<?php endif; ?>

	<div class="bps-pp__card">
		<?php if ( 'confirm' === $view ) : ?>
			<form method="post" action="<?php echo esc_url( $form['url'] ); ?>" class="bps-pp__form">
				<?php foreach ( $form['fields'] as $bps_k => $bps_v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $bps_k ); ?>" value="<?php echo esc_attr( (string) $bps_v ); ?>">
				<?php endforeach; ?>
				<?php if ( '' !== $img ) : ?>
					<figure class="bps-pp__figure">
						<a class="bps-pp__imglink" href="<?php echo esc_url( '' !== $full_url ? $full_url : $img ); ?>" target="_blank" rel="noopener noreferrer">
							<img class="bps-pp__img" src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $bps_alt ); ?>">
						</a>
						<figcaption>
							<a class="bps-pp__zoom" href="<?php echo esc_url( '' !== $full_url ? $full_url : $img ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Bekijk de proef op volle grootte', 'borduur-patch-studio' ); ?></a>
							<?php if ( '' !== $prev_url ) : ?>
								· <a class="bps-pp__zoom" href="<?php echo esc_url( $prev_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Vorige versie bekijken', 'borduur-patch-studio' ); ?></a>
							<?php endif; ?>
						</figcaption>
					</figure>
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
				<p class="bps-pp__info"><?php esc_html_e( 'Bekijk je proef en bevestig hieronder. Pas na jouw akkoord starten we de productie. Garen- en drukkleuren kunnen licht afwijken van je scherm.', 'borduur-patch-studio' ); ?></p>
				<?php if ( '' !== $error ) : ?>
					<p class="bps-pp__error" role="alert" id="bps-err"><?php echo esc_html( $error ); ?></p>
				<?php endif; ?>
				<?php if ( 'changes' === $action ) : ?>
					<label class="bps-pp__label" for="bps-note"><?php esc_html_e( 'Wat wil je aanpassen?', 'borduur-patch-studio' ); ?></label>
					<textarea id="bps-note" name="note" required maxlength="2000" aria-describedby="bps-note-help"<?php echo '' !== $error ? ' aria-invalid="true"' : ''; ?>><?php echo esc_textarea( $note ); ?></textarea>
					<p class="bps-pp__help" id="bps-note-help"><?php esc_html_e( 'Beschrijf zo concreet mogelijk wat er anders moet, bijvoorbeeld een kleur, de maat of een tekst.', 'borduur-patch-studio' ); ?></p>
					<div class="bps-pp__bar">
						<button type="submit" class="bps-pp__btn" data-bps-submit><?php esc_html_e( 'Verstuur mijn opmerking', 'borduur-patch-studio' ); ?></button>
					</div>
				<?php else : ?>
					<label class="bps-pp__label" for="bps-note"><?php esc_html_e( 'Opmerking (optioneel)', 'borduur-patch-studio' ); ?></label>
					<textarea id="bps-note" name="note" maxlength="2000" rows="2"><?php echo esc_textarea( $note ); ?></textarea>
					<div class="bps-pp__bar">
						<label class="bps-pp__check" for="bps-confirm">
							<input type="checkbox" id="bps-confirm" name="bps_confirm" value="1" required<?php echo '' !== $error ? ' aria-invalid="true" aria-describedby="bps-err"' : ''; ?>>
							<span><?php esc_html_e( 'Ik heb de spelling, de kleuren, de maat en het aantal nagekeken', 'borduur-patch-studio' ); ?></span>
						</label>
						<button type="submit" class="bps-pp__btn" data-bps-submit><?php esc_html_e( 'Ja, ik keur deze proef goed', 'borduur-patch-studio' ); ?></button>
					</div>
				<?php endif; ?>
			</form>
			<?php if ( '' !== $other_url ) : ?>
				<p class="bps-pp__alt">
					<a href="<?php echo esc_url( $other_url ); ?>"><?php echo esc_html( 'changes' === $action ? __( 'Toch goedkeuren', 'borduur-patch-studio' ) : __( 'Toch iets aanpassen', 'borduur-patch-studio' ) ); ?></a>
				</p>
			<?php endif; ?>

		<?php elseif ( 'done' === $view || 'answered' === $view ) : ?>
			<?php if ( '' !== $img ) : ?>
				<figure class="bps-pp__figure">
					<a class="bps-pp__imglink" href="<?php echo esc_url( '' !== $full_url ? $full_url : $img ); ?>" target="_blank" rel="noopener noreferrer">
						<img class="bps-pp__img" src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $bps_alt ); ?>" style="max-width:240px">
					</a>
				</figure>
			<?php endif; ?>
			<?php if ( '' !== $error ) : ?>
				<p class="bps-pp__error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<?php $bps_ok = $proof && 'approved' === $proof['status']; ?>
			<p class="<?php echo $bps_ok ? 'bps-pp__ok' : 'bps-pp__info'; ?>" role="status">
				<?php
				$bps_when = $proof ? BPS_OP::date_text( (string) $proof['responded_at'] ) : '';
				if ( 'done' === $view ) {
					echo esc_html( $bps_ok ? __( 'We starten de productie van je patch. Je hoort van ons zodra je bestelling onderweg is.', 'borduur-patch-studio' ) : __( 'We passen je ontwerp aan en sturen je een nieuwe digitale proef.', 'borduur-patch-studio' ) );
				} elseif ( $bps_ok ) {
					/* translators: %s: date */
					echo esc_html( sprintf( __( 'Je hebt deze proef al goedgekeurd op %s.', 'borduur-patch-studio' ), $bps_when ) );
				} else {
					/* translators: %s: date */
					echo esc_html( sprintf( __( 'Je vroeg op %s een aanpassing. We sturen je een nieuwe proef.', 'borduur-patch-studio' ), $bps_when ) );
				}
				?>
			</p>
			<?php if ( '' !== $pay_url ) : ?>
				<p><?php esc_html_e( 'Je kunt je bestelling nu betalen. Daarna starten we de productie.', 'borduur-patch-studio' ); ?></p>
				<p><a class="bps-pp__btn" href="<?php echo esc_url( $pay_url ); ?>" data-bps-pay><?php esc_html_e( 'Nu betalen', 'borduur-patch-studio' ); ?></a></p>
			<?php endif; ?>
			<?php if ( $proof && '' !== $proof['note'] ) : ?>
				<p class="bps-pp__line"><?php esc_html_e( 'Je opmerking', 'borduur-patch-studio' ); ?></p>
				<blockquote><?php echo esc_html( $proof['note'] ); ?></blockquote>
			<?php endif; ?>
			<?php if ( 'answered' === $view ) : ?>
				<p><?php esc_html_e( 'Wil je toch nog iets wijzigen? Neem dan contact met ons op.', 'borduur-patch-studio' ); ?></p>
			<?php endif; ?>

		<?php elseif ( 'cancel_confirm' === $view ) : ?>
			<p class="bps-pp__warn"><?php esc_html_e( 'Je hebt de proef nog niet goedgekeurd, dus je kunt je bestelling nog annuleren. Is ze nog niet betaald, dan annuleren we ze meteen. Is ze al betaald, dan nemen we contact met je op voor de terugbetaling.', 'borduur-patch-studio' ); ?></p>
			<?php if ( '' !== $error ) : ?>
				<p class="bps-pp__error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $form['url'] ); ?>">
				<?php foreach ( $form['fields'] as $bps_k => $bps_v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $bps_k ); ?>" value="<?php echo esc_attr( (string) $bps_v ); ?>">
				<?php endforeach; ?>
				<button type="submit" class="bps-pp__btn bps-pp__btn--danger" data-bps-cancel><?php esc_html_e( 'Ja, annuleer mijn bestelling', 'borduur-patch-studio' ); ?></button>
			</form>
			<?php if ( '' !== $other_url ) : ?>
				<p class="bps-pp__alt"><a href="<?php echo esc_url( $other_url ); ?>"><?php esc_html_e( 'Nee, terug naar mijn proef', 'borduur-patch-studio' ); ?></a></p>
			<?php endif; ?>

		<?php elseif ( 'cancel_done' === $view ) : ?>
			<p class="bps-pp__info" role="status"><?php esc_html_e( 'Je bestelling is geannuleerd. Je hoeft niets meer te betalen. Je krijgt een bevestiging per e-mail.', 'borduur-patch-studio' ); ?></p>

		<?php elseif ( 'cancel_requested' === $view ) : ?>
			<p class="bps-pp__info" role="status"><?php esc_html_e( 'Je bestelling is al betaald. We nemen zo snel mogelijk contact met je op om de annulering en de terugbetaling te regelen. We starten intussen niet met de productie.', 'borduur-patch-studio' ); ?></p>

		<?php elseif ( 'stopped' === $view ) : ?>
			<p class="bps-pp__info"><?php esc_html_e( 'Deze bestelling is geannuleerd of terugbetaald. Deze proef kan niet meer goedgekeurd worden. Is dit een vergissing? Neem dan contact met ons op.', 'borduur-patch-studio' ); ?></p>

		<?php elseif ( 'superseded' === $view ) : ?>
			<p class="bps-pp__info"><?php esc_html_e( 'Gebruik de knoppen in de laatste e-mail met je digitale proef.', 'borduur-patch-studio' ); ?></p>

		<?php else : ?>
			<p class="bps-pp__info"><?php esc_html_e( 'Controleer of je de volledige link uit de e-mail gebruikt, of neem contact met ons op.', 'borduur-patch-studio' ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $cancel_url && in_array( $view, array( 'confirm', 'answered', 'done' ), true ) ) : ?>
			<p class="bps-pp__alt"><a href="<?php echo esc_url( $cancel_url ); ?>" data-bps-cancel-link><?php esc_html_e( 'Bestelling annuleren', 'borduur-patch-studio' ); ?></a></p>
		<?php endif; ?>
	</div>
<?php
BPS_OP::template(
	'admin/proof-page-close.php',
	array(
		'contact'   => $contact,
		'use_theme' => $use_theme,
	)
);
