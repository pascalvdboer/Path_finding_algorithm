<?php
/**
 * Customer page of a whole-order proof: a card per patch with its own choice, and "Approve all" (atelier-flow-10).
 *
 * @package BorduurPatchStudio
 * @var string         $view      confirm|done|answered|superseded|invalid|stopped
 * @var string         $error     Validation message.
 * @var string         $lang      nl|fr
 * @var \WC_Order|null $order
 * @var array          $cards     [{item_id, ref, proof, open, rows, img, full_url, prev_url, choice, note}].
 * @var array          $form      {url, fields}.
 * @var string         $pay_url   Pay now or ''.
 * @var array          $contact   config.contact.
 * @var bool           $use_theme Wrap in the theme header/footer.
 */

use BorduurPatchStudio\Admin\Order_Production as BPS_OP;

defined( 'ABSPATH' ) || exit;

$cards    = isset( $cards ) && is_array( $cards ) ? $cards : array();
$bps_onum = $order ? (string) $order->get_order_number() : '';
$bps_open = array_filter( array_column( $cards, 'open' ) );

$bps_titles = array(
	'confirm'    => __( 'Je digitale proef', 'borduur-patch-studio' ),
	'done'       => __( 'Bedankt voor je antwoord', 'borduur-patch-studio' ),
	'answered'   => __( 'Je reactie op de digitale proef', 'borduur-patch-studio' ),
	'superseded' => __( 'Er is intussen een nieuwere proef verstuurd', 'borduur-patch-studio' ),
	'stopped'    => __( 'Deze bestelling is geannuleerd', 'borduur-patch-studio' ),
);
$bps_title  = $bps_titles[ $view ] ?? __( 'Deze link werkt niet (meer)', 'borduur-patch-studio' );
$bps_status = array(
	'approved'          => __( 'Goedgekeurd', 'borduur-patch-studio' ),
	'changes_requested' => __( 'Aanpassing gevraagd: je krijgt een nieuwe proef', 'borduur-patch-studio' ),
	'sent'              => __( 'Wacht op jouw goedkeuring', 'borduur-patch-studio' ),
	'cancelled'         => __( 'Geannuleerd', 'borduur-patch-studio' ),
	'none'              => __( 'We maken je digitale proef', 'borduur-patch-studio' ),
);

BPS_OP::template(
	'admin/proof-page-open.php',
	array(
		'bps_title' => $bps_title,
		'lang'      => $lang,
		'use_theme' => $use_theme,
	)
);
?>
	<?php if ( '' !== $bps_onum ) : ?>
		<p class="bps-pp__sub">
			<?php
			/* translators: %s: order number */
			echo esc_html( sprintf( __( 'Bestelling #%s', 'borduur-patch-studio' ), $bps_onum ) );
			if ( $cards ) {
				/* translators: %d: number of patches */
				echo esc_html( ' · ' . sprintf( _n( '%d patch', '%d patches', count( $cards ), 'borduur-patch-studio' ), count( $cards ) ) );
			}
			?>
		</p>
	<?php endif; ?>

	<?php if ( in_array( $view, array( 'confirm', 'done', 'answered' ), true ) ) : ?>
		<form method="post" action="<?php echo esc_url( $form['url'] ); ?>">
			<?php foreach ( $form['fields'] as $bps_k => $bps_v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $bps_k ); ?>" value="<?php echo esc_attr( (string) $bps_v ); ?>">
			<?php endforeach; ?>
			<?php if ( 'done' === $view ) : ?>
				<p class="bps-pp__ok" role="status"><?php esc_html_e( 'We hebben je antwoord goed ontvangen. Goedgekeurde patches gaan in productie; voor de andere sturen we je een nieuwe proef.', 'borduur-patch-studio' ); ?></p>
			<?php elseif ( 'confirm' === $view ) : ?>
				<p class="bps-pp__info"><?php esc_html_e( 'Kijk elke patch na en kies per patch: goedkeuren of iets aanpassen. Pas na jouw akkoord starten we de productie. Garen- en drukkleuren kunnen licht afwijken van je scherm.', 'borduur-patch-studio' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $error ) : ?>
				<p class="bps-pp__error" role="alert" id="bps-err"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<?php foreach ( $cards as $bps_c ) : ?>
				<?php
				$bps_id = (int) $bps_c['item_id'];
				/* translators: %s: design ref */
				$bps_alt = sprintf( __( 'Digitale proef van patch %s', 'borduur-patch-studio' ), $bps_c['ref'] );
				?>
				<section class="bps-pp__card" aria-labelledby="bps-line-<?php echo (int) $bps_id; ?>">
					<h2 id="bps-line-<?php echo (int) $bps_id; ?>">
						<?php
						/* translators: %s: design ref */
						echo esc_html( sprintf( __( 'Patch %s', 'borduur-patch-studio' ), $bps_c['ref'] ) );
						?>
					</h2>
					<?php if ( '' !== $bps_c['img'] ) : ?>
						<figure class="bps-pp__figure">
							<a class="bps-pp__imglink" href="<?php echo esc_url( '' !== $bps_c['full_url'] ? $bps_c['full_url'] : $bps_c['img'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img class="bps-pp__img" src="<?php echo esc_url( $bps_c['img'] ); ?>" alt="<?php echo esc_attr( $bps_alt ); ?>">
							</a>
							<figcaption>
								<a class="bps-pp__zoom" href="<?php echo esc_url( '' !== $bps_c['full_url'] ? $bps_c['full_url'] : $bps_c['img'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Bekijk de proef op volle grootte', 'borduur-patch-studio' ); ?></a>
								<?php if ( '' !== $bps_c['prev_url'] ) : ?>
									· <a class="bps-pp__zoom" href="<?php echo esc_url( $bps_c['prev_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Vorige versie bekijken', 'borduur-patch-studio' ); ?></a>
								<?php endif; ?>
							</figcaption>
						</figure>
					<?php endif; ?>
					<?php if ( $bps_c['rows'] ) : ?>
						<table>
							<?php foreach ( $bps_c['rows'] as $bps_row ) : ?>
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
					<?php if ( $bps_c['open'] ) : ?>
						<fieldset class="bps-pp__choices">
							<legend><?php esc_html_e( 'Jouw keuze voor deze patch', 'borduur-patch-studio' ); ?></legend>
							<label class="bps-pp__choice"><input type="radio" name="choice[<?php echo (int) $bps_id; ?>]" value="approve"<?php checked( 'approve', $bps_c['choice'] ); ?>> <?php esc_html_e( 'Goedkeuren', 'borduur-patch-studio' ); ?></label>
							<label class="bps-pp__choice"><input type="radio" name="choice[<?php echo (int) $bps_id; ?>]" value="changes"<?php checked( 'changes', $bps_c['choice'] ); ?>> <?php esc_html_e( 'Iets aanpassen', 'borduur-patch-studio' ); ?></label>
						</fieldset>
						<label class="bps-pp__label" for="bps-note-<?php echo (int) $bps_id; ?>"><?php esc_html_e( 'Opmerking (nodig als je iets wil aanpassen)', 'borduur-patch-studio' ); ?></label>
						<textarea id="bps-note-<?php echo (int) $bps_id; ?>" name="notes[<?php echo (int) $bps_id; ?>]" maxlength="2000" rows="2"><?php echo esc_textarea( $bps_c['note'] ); ?></textarea>
					<?php else : ?>
						<p class="<?php echo 'approved' === $bps_c['proof']['status'] ? 'bps-pp__ok' : 'bps-pp__info'; ?>"><?php echo esc_html( $bps_status[ $bps_c['proof']['status'] ] ?? '' ); ?></p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<?php if ( 'confirm' === $view && $bps_open ) : ?>
				<div class="bps-pp__card bps-pp__bar">
					<label class="bps-pp__check" for="bps-confirm">
						<input type="checkbox" id="bps-confirm" name="bps_confirm" value="1"<?php echo '' !== $error ? ' aria-describedby="bps-err"' : ''; ?>>
						<span><?php esc_html_e( 'Ik heb de spelling, de kleuren, de maat en het aantal nagekeken', 'borduur-patch-studio' ); ?></span>
					</label>
					<p><button type="submit" class="bps-pp__btn" name="approve_all" value="1" data-bps-approve-all><?php esc_html_e( 'Alles goedkeuren', 'borduur-patch-studio' ); ?></button></p>
					<p><button type="submit" class="bps-pp__btn bps-pp__btn--ghost" data-bps-submit><?php esc_html_e( 'Mijn keuzes per patch versturen', 'borduur-patch-studio' ); ?></button></p>
				</div>
			<?php endif; ?>
		</form>
		<?php if ( '' !== $pay_url ) : ?>
			<p><a class="bps-pp__btn" href="<?php echo esc_url( $pay_url ); ?>" data-bps-pay><?php esc_html_e( 'Nu betalen', 'borduur-patch-studio' ); ?></a></p>
		<?php endif; ?>
	<?php else : ?>
		<div class="bps-pp__card">
			<p class="bps-pp__info">
				<?php
				if ( 'stopped' === $view ) {
					esc_html_e( 'Deze bestelling is geannuleerd of terugbetaald. Deze proef kan niet meer goedgekeurd worden. Is dit een vergissing? Neem dan contact met ons op.', 'borduur-patch-studio' );
				} elseif ( 'superseded' === $view ) {
					esc_html_e( 'Gebruik de knoppen in de laatste e-mail met je digitale proef.', 'borduur-patch-studio' );
				} else {
					esc_html_e( 'Controleer of je de volledige link uit de e-mail gebruikt, of neem contact met ons op.', 'borduur-patch-studio' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>
<?php
BPS_OP::template(
	'admin/proof-page-close.php',
	array(
		'contact'   => $contact,
		'use_theme' => $use_theme,
	)
);
