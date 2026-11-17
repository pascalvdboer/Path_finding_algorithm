<?php
/**
 * Tab "Technieken". Variables in scope: $config, $defaults.
 *
 * @package BorduurPatchStudio
 */

use BorduurPatchStudio\Admin\Settings_Page as P;
use BorduurPatchStudio\Plugin;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

$bps_rule_labels = array(
	'sizeMm'             => __( 'Patchmaat', 'borduur-patch-studio' ),
	'textCapsMm'         => __( 'Hoogte hoofdletters', 'borduur-patch-studio' ),
	'textLowerXHeightMm' => __( 'Hoogte kleine letters (x-hoogte)', 'borduur-patch-studio' ),
	'serifScriptFactor'  => __( 'Strenger voor schreef- en sierletters (factor)', 'borduur-patch-studio' ),
	'lineMm'             => __( 'Dunste lijn', 'borduur-patch-studio' ),
	'gapMm'              => __( 'Kleinste tussenruimte', 'borduur-patch-studio' ),
	'smallestElementMm'  => __( 'Kleinste detail', 'borduur-patch-studio' ),
	'colors'             => __( 'Kleuren', 'borduur-patch-studio' ),
);
$bps_sub_labels  = array(
	'hard'           => __( 'Absoluut minimum', 'borduur-patch-studio' ),
	'hardWithMerrow' => __( 'Minimum met omgezoomde rand', 'borduur-patch-studio' ),
	'comfort'        => __( 'Mooi resultaat vanaf', 'borduur-patch-studio' ),
	'comfortMin'     => __( 'Mooi resultaat vanaf', 'borduur-patch-studio' ),
	'comfortMax'     => __( 'Aangeraden maximum', 'borduur-patch-studio' ),
	'max'            => __( 'Maximum', 'borduur-patch-studio' ),
	'runStitchHard'  => __( 'Minimum met loopsteek', 'borduur-patch-studio' ),
);
?>
<div class="bps-grid">
<?php
foreach ( P::techniques( $config ) as $bps_id => $bps_t ) :
	$bps_def    = $defaults['techniques'][ $bps_id ] ?? array();
	$bps_has    = is_array( $bps_t['basePrice'] ?? null );
	$bps_status = empty( $bps_t['enabled'] ) ? 'off' : ( 'order' === ( $bps_t['mode'] ?? '' ) ? 'order' : 'quote' );
	$bps_pills  = array(
		'off'   => __( 'Verborgen', 'borduur-patch-studio' ),
		'order' => __( 'Online bestelbaar', 'borduur-patch-studio' ),
		'quote' => __( 'Offerte op aanvraag', 'borduur-patch-studio' ),
	);
	?>
	<section class="bps-card bps-card--tech" aria-labelledby="bps-tech-<?php echo esc_attr( $bps_id ); ?>">
		<header class="bps-card__head">
			<h2 id="bps-tech-<?php echo esc_attr( $bps_id ); ?>"><?php echo esc_html( Settings::label( $bps_t['label'] ?? $bps_id ) ); ?></h2>
			<span class="bps-pill bps-pill--<?php echo esc_attr( $bps_status ); ?>"><?php echo esc_html( $bps_pills[ $bps_status ] ); ?></span>
		</header>
		<p class="bps-card__pitch"><?php echo esc_html( Settings::label( $bps_t['pitch'] ?? '' ) ); ?></p>
		<?php
		P::toggle( array( 'techniques', $bps_id, 'enabled' ), ! empty( $bps_t['enabled'] ), __( 'Tonen in de ontwerptool', 'borduur-patch-studio' ) );

		$bps_order_label = $bps_has ? __( 'Online bestellen met prijs', 'borduur-patch-studio' ) : '!' . __( 'Online bestellen (vul eerst prijzen in bij Prijzen)', 'borduur-patch-studio' );
		P::select(
			array( 'techniques', $bps_id, 'mode' ),
			(string) ( $bps_t['mode'] ?? 'quote' ),
			__( 'Hoe bestelt de klant?', 'borduur-patch-studio' ),
			array(
				'order' => $bps_order_label,
				'quote' => __( 'Offerte op aanvraag (geen prijs tonen)', 'borduur-patch-studio' ),
			),
			__( 'Bij een offerte vult de klant zijn ontwerp in en krijg jij een aanvraag met alles erbij.', 'borduur-patch-studio' )
		);
		echo '<div class="bps-row">';
		P::number(
			array( 'techniques', $bps_id, 'minQty' ),
			$bps_t['minQty'] ?? 1,
			__( 'Minimum aantal', 'borduur-patch-studio' ),
			array(
				'unit'     => __( 'stuks', 'borduur-patch-studio' ),
				'decimals' => 0,
				'min'      => 1,
				'default'  => $bps_def['minQty'] ?? null,
			)
		);
		P::number(
			array( 'techniques', $bps_id, 'qtyStep' ),
			$bps_t['qtyStep'] ?? 1,
			__( 'Bestellen per', 'borduur-patch-studio' ),
			array(
				'unit'     => __( 'stuks', 'borduur-patch-studio' ),
				'decimals' => 0,
				'min'      => 1,
				'default'  => $bps_def['qtyStep'] ?? null,
				'help'     => __( 'Bijvoorbeeld 10 als je enkel per tiental maakt.', 'borduur-patch-studio' ),
			)
		);
		echo '</div>';

		if ( ! empty( $bps_t['rules'] ) && is_array( $bps_t['rules'] ) ) :
			?>
			<details class="bps-details">
				<summary><?php esc_html_e( 'Technische grenzen voor het advies (gevorderd)', 'borduur-patch-studio' ); ?></summary>
				<p class="bps-help"><?php esc_html_e( 'Hiermee beslist de tool of iets haalbaar is. Alle maten in millimeter op de echte patch. Laat staan als je twijfelt.', 'borduur-patch-studio' ); ?></p>
				<?php
				foreach ( $bps_t['rules'] as $bps_rk => $bps_rv ) :
					$bps_rlabel = $bps_rule_labels[ $bps_rk ] ?? $bps_rk;
					$bps_unit   = 'mm' === substr( (string) $bps_rk, -2 ) || 'Mm' === substr( (string) $bps_rk, -2 ) ? 'mm' : '';
					if ( is_int( $bps_rv ) || is_float( $bps_rv ) ) {
						P::number(
							array( 'techniques', $bps_id, 'rules', $bps_rk ),
							$bps_rv,
							$bps_rlabel,
							array(
								'decimals' => 2,
								'default'  => $bps_def['rules'][ $bps_rk ] ?? null,
							)
						);
						continue;
					}
					if ( ! is_array( $bps_rv ) ) {
						continue;
					}
					$bps_fields = array();
					foreach ( $bps_rv as $bps_sk => $bps_sv ) {
						$numeric_null = null === $bps_sv && in_array( $bps_sk, array( 'max', 'comfortMax' ), true );
						if ( is_int( $bps_sv ) || is_float( $bps_sv ) || $numeric_null ) {
							$bps_fields[ $bps_sk ] = $bps_sv;
						}
					}
					if ( ! $bps_fields ) {
						continue;
					}
					echo '<fieldset class="bps-rule"><legend>' . esc_html( $bps_rlabel ) . '</legend><div class="bps-row">';
					foreach ( $bps_fields as $bps_sk => $bps_sv ) {
						$bps_is_color = 'colors' === $bps_rk;
						P::number(
							array( 'techniques', $bps_id, 'rules', $bps_rk, $bps_sk ),
							$bps_sv,
							$bps_sub_labels[ $bps_sk ] ?? $bps_sk,
							array(
								'unit'        => $bps_is_color ? __( 'kleuren', 'borduur-patch-studio' ) : ( 'sizeMm' === $bps_rk || $bps_unit ? 'mm' : '' ),
								'decimals'    => $bps_is_color ? 0 : 2,
								'placeholder' => null === $bps_sv ? __( 'geen limiet', 'borduur-patch-studio' ) : null,
								'default'     => $bps_def['rules'][ $bps_rk ][ $bps_sk ] ?? null,
							)
						);
					}
					echo '</div></fieldset>';
				endforeach;
				?>
			</details>
		<?php endif; ?>
		<p class="bps-help">
			<?php
			printf(
				/* translators: %s: link to the texts tab */
				esc_html__( 'Levertijd per techniek stel je in bij %s.', 'borduur-patch-studio' ),
				'<a href="' . esc_url( Plugin::settings_url( 'teksten' ) ) . '">' . esc_html__( 'Teksten & beloftes', 'borduur-patch-studio' ) . '</a>'
			);
			?>
		</p>
	</section>
<?php endforeach; ?>
</div>

<?php if ( ! empty( $config['specials'] ) && is_array( $config['specials'] ) ) : ?>
<section class="bps-card" aria-labelledby="bps-specials">
	<header class="bps-card__head"><h2 id="bps-specials"><?php esc_html_e( 'Speciale afwerkingen', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-card__pitch"><?php esc_html_e( 'Deze worden enkel als mogelijkheid "op aanvraag" vermeld in het advies, zonder prijs. Zet uit wat je niet aanbiedt.', 'borduur-patch-studio' ); ?></p>
	<?php
	foreach ( $config['specials'] as $bps_sid => $bps_s ) {
		if ( is_array( $bps_s ) ) {
			P::toggle( array( 'specials', $bps_sid, 'enabled' ), ! empty( $bps_s['enabled'] ), Settings::label( $bps_s['label'] ?? $bps_sid ) );
		}
	}
	?>
</section>
<?php endif; ?>
