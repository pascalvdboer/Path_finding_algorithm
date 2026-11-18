<?php
/**
 * My account › Mijn ontwerpen: the customer's saved, carted, quoted and ordered patch designs.
 *
 * Override: yourtheme/woocommerce/myaccount/designs.php
 *
 * Every action works without JavaScript: rename and delete are nonce-protected POST forms, "Opnieuw bestellen" is a
 * nonce link (like WooCommerce's own "Order again").
 *
 * @package BorduurPatchStudio
 * @var array  $designs    Cards (see Woo\Account::card()).
 * @var int    $total      Number of designs.
 * @var int    $pages      Number of pages.
 * @var int    $page       Current page.
 * @var string $base_url   URL of this endpoint.
 * @var string $studio_url URL of the studio.
 */

defined( 'ABSPATH' ) || exit;
?>
<style>
	.bps-account-designs{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin:0 0 24px;padding:0;list-style:none}
	.bps-account-design{border:1px solid #E3EBF2;border-radius:8px;padding:16px;display:flex;flex-direction:column;gap:10px;background:#fff}
	.bps-account-design__img{display:block;width:100%;aspect-ratio:1/1;object-fit:contain;background:#F7FBFE;border-radius:6px}
	.bps-account-design__title{margin:0;font-size:1.05em;line-height:1.3;word-break:break-word}
	.bps-account-design__meta{margin:0;color:#555;font-size:.92em;line-height:1.45}
	.bps-account-design__status{display:inline-block;padding:2px 8px;border-radius:999px;background:#EEF6FB;color:#0A1F44;font-size:.85em;font-weight:600}
	.bps-account-design__actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:auto}
	.bps-account-design__actions .button{min-height:44px;display:inline-flex;align-items:center}
	.bps-account-design details{font-size:.92em}
	.bps-account-design summary{cursor:pointer;min-height:44px;display:flex;align-items:center}
	.bps-account-design form{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 0}
	.bps-account-design input[type="text"]{flex:1 1 140px;min-height:44px}
	.bps-account-designs__pages{display:flex;gap:12px;align-items:center}
</style>

<?php if ( empty( $designs ) ) : ?>
	<div class="woocommerce-info bps-account-designs__empty">
		<?php esc_html_e( 'Je hebt nog geen bewaarde ontwerpen. Ontwerp je patch in de studio en kies "Bewaren": dan vind je hem hier terug.', 'borduur-patch-studio' ); ?>
		<?php if ( ! empty( $studio_url ) ) : ?>
			<a class="woocommerce-Button button" href="<?php echo esc_url( $studio_url ); ?>"><?php esc_html_e( 'Ontwerp een patch', 'borduur-patch-studio' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;
?>

<p class="bps-account-designs__intro">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: number of designs */
			_n( 'Je hebt %s ontwerp. Ga verder waar je gebleven was, of bestel een eerder ontwerp opnieuw.', 'Je hebt %s ontwerpen. Ga verder waar je gebleven was, of bestel een eerder ontwerp opnieuw.', (int) $total, 'borduur-patch-studio' ),
			number_format_i18n( (int) $total )
		)
	);
	?>
</p>

<ul class="bps-account-designs">
	<?php foreach ( $designs as $d ) : ?>
		<li class="bps-account-design" data-design="<?php echo esc_attr( (string) $d['id'] ); ?>" data-ref="<?php echo esc_attr( $d['ref'] ); ?>">
			<?php if ( ! empty( $d['preview'] ) ) : ?>
				<img class="bps-account-design__img" src="<?php echo esc_url( $d['preview'] ); ?>"
					<?php if ( ! empty( $d['preview2x'] ) && $d['preview2x'] !== $d['preview'] ) : ?>
						srcset="<?php echo esc_url( $d['preview'] ); ?> 1x, <?php echo esc_url( $d['preview2x'] ); ?> 2x"
					<?php endif; ?>
					width="300" height="300" loading="lazy" decoding="async"
					alt="<?php echo esc_attr( sprintf( /* translators: %s: design name or reference */ __( 'Voorbeeld van %s', 'borduur-patch-studio' ), $d['label'] ) ); ?>" />
			<?php endif; ?>
			<h3 class="bps-account-design__title"><?php echo esc_html( $d['label'] ); ?></h3>
			<p class="bps-account-design__meta">
				<span class="bps-account-design__status"><?php echo esc_html( $d['statusLabel'] ); ?></span><br>
				<?php if ( $d['title'] ) : ?>
					<?php echo esc_html( $d['ref'] ); ?> ·
				<?php endif; ?>
				<?php echo esc_html( implode( ' · ', array_filter( array( $d['technique'], $d['size'], $d['qty'] ? sprintf( /* translators: %s: quantity */ _n( '%s stuk', '%s stuks', (int) $d['qty'], 'borduur-patch-studio' ), number_format_i18n( (int) $d['qty'] ) ) : '' ) ) ) ); ?>
				<?php if ( $d['date'] ) : ?>
					<br><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Gemaakt op %s', 'borduur-patch-studio' ), $d['date'] ) ); ?>
				<?php endif; ?>
				<?php if ( $d['orderUrl'] ) : ?>
					<br><a href="<?php echo esc_url( $d['orderUrl'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Bestelling #%s', 'borduur-patch-studio' ), (string) $d['orderId'] ) ); ?></a>
				<?php endif; ?>
			</p>
			<div class="bps-account-design__actions">
				<?php if ( $d['reorderUrl'] ) : ?>
					<a class="woocommerce-button button bps-account-design__reorder" href="<?php echo esc_url( $d['reorderUrl'] ); ?>"><?php esc_html_e( 'Opnieuw bestellen', 'borduur-patch-studio' ); ?></a>
				<?php endif; ?>
				<a class="woocommerce-button button <?php echo $d['reorderUrl'] ? 'alt-secondary' : 'alt'; ?> bps-account-design__continue" href="<?php echo esc_url( $d['restoreUrl'] ); ?>"><?php esc_html_e( 'Verder ontwerpen', 'borduur-patch-studio' ); ?></a>
			</div>
			<details>
				<summary><?php esc_html_e( 'Naam wijzigen', 'borduur-patch-studio' ); ?></summary>
				<form method="post" action="<?php echo esc_url( $base_url ); ?>">
					<label class="screen-reader-text" for="bps-title-<?php echo esc_attr( (string) $d['id'] ); ?>"><?php esc_html_e( 'Nieuwe naam', 'borduur-patch-studio' ); ?></label>
					<input type="text" id="bps-title-<?php echo esc_attr( (string) $d['id'] ); ?>" name="bps_title" maxlength="80" value="<?php echo esc_attr( $d['title'] ); ?>" placeholder="<?php esc_attr_e( 'Bv. Clubpatch 2027', 'borduur-patch-studio' ); ?>" />
					<input type="hidden" name="bps_design" value="<?php echo esc_attr( (string) $d['id'] ); ?>" />
					<input type="hidden" name="bps_account_action" value="rename" />
					<?php wp_nonce_field( 'bps_account_design_' . $d['id'], 'bps_nonce' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Bewaren', 'borduur-patch-studio' ); ?></button>
				</form>
			</details>
			<details>
				<summary><?php esc_html_e( 'Verwijderen', 'borduur-patch-studio' ); ?></summary>
				<form method="post" action="<?php echo esc_url( $base_url ); ?>">
					<p class="bps-account-design__meta">
						<?php
						if ( 'ordered' === $d['status'] || 'quoted' === $d['status'] ) {
							esc_html_e( 'Het ontwerp verdwijnt uit deze lijst. Je bestelling of offerte blijft gewoon bewaard.', 'borduur-patch-studio' );
						} else {
							esc_html_e( 'Het ontwerp en je bestand worden definitief verwijderd.', 'borduur-patch-studio' );
						}
						?>
					</p>
					<input type="hidden" name="bps_design" value="<?php echo esc_attr( (string) $d['id'] ); ?>" />
					<input type="hidden" name="bps_account_action" value="delete" />
					<?php wp_nonce_field( 'bps_account_design_' . $d['id'], 'bps_nonce' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Ja, verwijderen', 'borduur-patch-studio' ); ?></button>
				</form>
			</details>
		</li>
	<?php endforeach; ?>
</ul>

<?php if ( $pages > 1 ) : ?>
	<nav class="bps-account-designs__pages woocommerce-pagination" aria-label="<?php esc_attr_e( 'Pagina’s met ontwerpen', 'borduur-patch-studio' ); ?>">
		<?php if ( $page > 1 ) : ?>
			<a class="woocommerce-button button" href="<?php echo esc_url( add_query_arg( 'bps_page', $page - 1, $base_url ) ); ?>"><?php esc_html_e( 'Vorige', 'borduur-patch-studio' ); ?></a>
		<?php endif; ?>
		<span><?php echo esc_html( sprintf( /* translators: 1: current page, 2: number of pages */ __( 'Pagina %1$s van %2$s', 'borduur-patch-studio' ), number_format_i18n( (int) $page ), number_format_i18n( (int) $pages ) ) ); ?></span>
		<?php if ( $page < $pages ) : ?>
			<a class="woocommerce-button button" href="<?php echo esc_url( add_query_arg( 'bps_page', $page + 1, $base_url ) ); ?>"><?php esc_html_e( 'Volgende', 'borduur-patch-studio' ); ?></a>
		<?php endif; ?>
	</nav>
<?php endif; ?>
