<?php
/**
 * Closing of the customer proof pages (contact line, back link, wrapper, theme footer or document end).
 *
 * @package BorduurPatchStudio
 * @var array $contact   config.contact.
 * @var bool  $use_theme Wrapped in the theme.
 */

defined( 'ABSPATH' ) || exit;

$bps_phone = (string) ( $contact['phone'] ?? '' );
$bps_mail  = (string) ( $contact['email'] ?? '' );
$bps_wa    = preg_replace( '/\D+/', '', (string) ( $contact['whatsapp'] ?? '' ) );
?>
	<?php if ( '' !== $bps_phone || '' !== $bps_mail ) : ?>
		<p class="bps-pp__contact">
			<?php esc_html_e( 'Vragen? Neem contact op', 'borduur-patch-studio' ); ?>:
			<?php if ( '' !== $bps_phone ) : ?>
				<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $bps_phone ) ); ?>"><?php echo esc_html( $bps_phone ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $bps_wa ) : ?>
				· <a href="<?php echo esc_url( 'https://wa.me/' . $bps_wa ); ?>" rel="noopener noreferrer">WhatsApp</a>
			<?php endif; ?>
			<?php if ( '' !== $bps_mail ) : ?>
				· <a href="<?php echo esc_url( 'mailto:' . $bps_mail ); ?>"><?php echo esc_html( $bps_mail ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php if ( ! $use_theme ) : ?>
		<p class="bps-pp__alt"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Terug naar de website', 'borduur-patch-studio' ); ?></a></p>
	<?php endif; ?>
<?php if ( $use_theme ) : ?>
</div>
	<?php
	get_footer();
else :
	?>
</main>
</body>
</html>
<?php endif; ?>
