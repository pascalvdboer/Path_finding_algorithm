<?php
/**
 * Tab "Kleuren". Variables in scope: $config, $defaults.
 *
 * @package BorduurPatchStudio
 */

use BorduurPatchStudio\Admin\Settings_Page as P;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

$bps_threads  = array_values( array_filter( (array) ( $config['threads'] ?? array() ), static fn( $t ) => is_array( $t ) && isset( $t['code'] ) ) );
$bps_disabled = array_map( 'strval', (array) ( $config['threadsDisabled'] ?? array() ) );
$bps_active   = 0;
foreach ( $bps_threads as $bps_t ) {
	if ( ! in_array( (string) $bps_t['code'], $bps_disabled, true ) && ( $bps_t['stock'] ?? true ) !== false ) {
		++$bps_active;
	}
}
$bps_brand = (string) ( $config['threadBrand'] ?? '' );
?>
<section class="bps-card bps-card--wide" aria-labelledby="bps-threads">
	<header class="bps-card__head">
		<h2 id="bps-threads"><?php echo esc_html( $bps_brand ? sprintf( /* translators: %s: thread brand */ __( 'Garenkleuren (%s)', 'borduur-patch-studio' ), $bps_brand ) : __( 'Garenkleuren', 'borduur-patch-studio' ) ); ?></h2>
		<span class="bps-pill bps-pill--order" data-bps-thread-count>
			<?php
			/* translators: 1: active colors, 2: total colors */
			echo esc_html( sprintf( __( '%1$d van %2$d beschikbaar', 'borduur-patch-studio' ), $bps_active, count( $bps_threads ) ) );
			?>
		</span>
	</header>
	<?php if ( ! $bps_threads ) : ?>
		<p class="bps-help"><?php esc_html_e( 'Het garenpalet is nog niet geladen.', 'borduur-patch-studio' ); ?></p>
	<?php else : ?>
		<input type="hidden" name="bps[threads_present]" value="1">
		<div class="bps-toolbar">
			<label class="screen-reader-text" for="bps-thread-filter"><?php esc_html_e( 'Zoek een kleur', 'borduur-patch-studio' ); ?></label>
			<input type="search" id="bps-thread-filter" class="bps-search" placeholder="<?php esc_attr_e( 'Zoek op naam of nummer…', 'borduur-patch-studio' ); ?>" data-bps-filter="#bps-thread-grid">
			<button type="button" class="button" data-bps-check-all="#bps-thread-grid"><?php esc_html_e( 'Alles aan', 'borduur-patch-studio' ); ?></button>
			<button type="button" class="button" data-bps-check-none="#bps-thread-grid"><?php esc_html_e( 'Alles uit', 'borduur-patch-studio' ); ?></button>
		</div>
		<ul class="bps-swatches" id="bps-thread-grid">
			<?php
			foreach ( $bps_threads as $bps_t ) :
				$bps_code  = (string) $bps_t['code'];
				$bps_stock = ( $bps_t['stock'] ?? true ) !== false;
				$bps_on    = $bps_stock && ! in_array( $bps_code, $bps_disabled, true );
				$bps_name  = Settings::label( $bps_t['name'] ?? $bps_code );
				$bps_hex   = sanitize_hex_color( (string) ( $bps_t['hex'] ?? '' ) );
				$bps_id    = 'bps-thread-' . sanitize_html_class( $bps_code );
				?>
				<li class="bps-swatch<?php echo $bps_stock ? '' : ' is-unavailable'; ?>" data-bps-search="<?php echo esc_attr( strtolower( $bps_name . ' ' . $bps_code ) ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $bps_id ); ?>" name="bps[threads_on][]" value="<?php echo esc_attr( $bps_code ); ?>" <?php checked( $bps_on ); ?> <?php disabled( ! $bps_stock ); ?>>
					<label for="<?php echo esc_attr( $bps_id ); ?>">
						<span class="bps-swatch__chip" style="background:<?php echo esc_attr( $bps_hex ? $bps_hex : '#ccc' ); ?>" aria-hidden="true"></span>
						<span class="bps-swatch__name"><?php echo esc_html( $bps_name ); ?></span>
						<span class="bps-swatch__code"><?php echo esc_html( $bps_code ); ?><?php echo $bps_stock ? '' : ' · ' . esc_html__( 'niet leverbaar', 'borduur-patch-studio' ); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="bps-row">
			<?php
			$bps_choices = array();
			foreach ( $bps_threads as $bps_t ) {
				$bps_choices[ (string) $bps_t['code'] ] = $bps_t['code'] . ' · ' . Settings::label( $bps_t['name'] ?? '' );
			}
			P::select( array( 'threadSpecial', 'black' ), (string) ( $config['threadSpecial']['black'] ?? '' ), __( 'Standaard zwart garen', 'borduur-patch-studio' ), $bps_choices );
			P::select( array( 'threadSpecial', 'white' ), (string) ( $config['threadSpecial']['white'] ?? '' ), __( 'Standaard wit garen', 'borduur-patch-studio' ), $bps_choices );
			?>
		</div>
	<?php endif; ?>
</section>

<?php
$bps_lists = array(
	'fabrics'  => array(
		'title' => __( 'Stofkleuren (achtergrond van de patch)', 'borduur-patch-studio' ),
		'help'  => __( 'De kleuren twill of stof die je op voorraad hebt. De tool kiest automatisch de stof waarop het ontwerp het best uitkomt; de klant kan wijzigen.', 'borduur-patch-studio' ),
	),
	'leathers' => array(
		'title' => __( 'Ledertinten (gelaserd leder)', 'borduur-patch-studio' ),
		'help'  => __( '"Gravure" is de kleur van de ingebrande lijnen.', 'borduur-patch-studio' ),
	),
);
foreach ( $bps_lists as $bps_key => $bps_info ) :
	$bps_rows = array_values( (array) ( $config[ $bps_key ] ?? array() ) );
	$bps_leather = 'leathers' === $bps_key;
	?>
<section class="bps-card bps-card--wide" aria-labelledby="bps-<?php echo esc_attr( $bps_key ); ?>">
	<header class="bps-card__head"><h2 id="bps-<?php echo esc_attr( $bps_key ); ?>"><?php echo esc_html( $bps_info['title'] ); ?></h2></header>
	<p class="bps-help"><?php echo esc_html( $bps_info['help'] ); ?></p>
	<div class="bps-table-wrap">
	<table class="bps-table bps-table--inputs bps-fabrics" data-bps-rows>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Kleur', 'borduur-patch-studio' ); ?></th>
			<?php if ( $bps_leather ) : ?>
				<th scope="col"><?php esc_html_e( 'Gravure', 'borduur-patch-studio' ); ?></th>
			<?php endif; ?>
			<th scope="col"><?php esc_html_e( 'Naam (NL)', 'borduur-patch-studio' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Naam (FR)', 'borduur-patch-studio' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Verwijderen', 'borduur-patch-studio' ); ?></th>
		</tr></thead>
		<tbody>
		<?php
		$bps_rows[] = array(
			'id'      => '',
			'hex'     => '#cccccc',
			'engrave' => '',
			'label'   => array(
				'nl' => '',
				'fr' => '',
			),
			'_new'    => true,
		);
		foreach ( $bps_rows as $bps_i => $bps_r ) :
			$bps_base = array( $bps_key, $bps_i );
			$bps_new  = ! empty( $bps_r['_new'] );
			?>
			<tr<?php echo $bps_new ? ' class="bps-new-row"' : ''; ?>>
				<td>
					<input type="hidden" name="<?php echo esc_attr( P::name( array_merge( $bps_base, array( 'id' ) ) ) ); ?>" value="<?php echo esc_attr( (string) ( $bps_r['id'] ?? '' ) ); ?>">
					<label class="screen-reader-text" for="<?php echo esc_attr( P::id( array_merge( $bps_base, array( 'hex' ) ) ) ); ?>"><?php esc_html_e( 'Kleur', 'borduur-patch-studio' ); ?></label>
					<input type="color" id="<?php echo esc_attr( P::id( array_merge( $bps_base, array( 'hex' ) ) ) ); ?>" name="<?php echo esc_attr( P::name( array_merge( $bps_base, array( 'hex' ) ) ) ); ?>" value="<?php echo esc_attr( (string) ( sanitize_hex_color( (string) ( $bps_r['hex'] ?? '' ) ) ?: '#cccccc' ) ); ?>">
				</td>
				<?php if ( $bps_leather ) : ?>
				<td>
					<label class="screen-reader-text" for="<?php echo esc_attr( P::id( array_merge( $bps_base, array( 'engrave' ) ) ) ); ?>"><?php esc_html_e( 'Gravure', 'borduur-patch-studio' ); ?></label>
					<input type="color" id="<?php echo esc_attr( P::id( array_merge( $bps_base, array( 'engrave' ) ) ) ); ?>" name="<?php echo esc_attr( P::name( array_merge( $bps_base, array( 'engrave' ) ) ) ); ?>" value="<?php echo esc_attr( (string) ( sanitize_hex_color( (string) ( $bps_r['engrave'] ?? '' ) ) ?: '#4a2810' ) ); ?>">
				</td>
				<?php endif; ?>
				<td>
					<?php
					P::text(
						array_merge( $bps_base, array( 'label', 'nl' ) ),
						$bps_r['label']['nl'] ?? '',
						__( 'Naam (NL)', 'borduur-patch-studio' ),
						array(
							'lang'        => 'nl-BE',
							'placeholder' => $bps_new ? __( 'Nieuwe kleur toevoegen…', 'borduur-patch-studio' ) : null,
						)
					);
					?>
				</td>
				<td><?php P::text( array_merge( $bps_base, array( 'label', 'fr' ) ), $bps_r['label']['fr'] ?? '', __( 'Naam (FR)', 'borduur-patch-studio' ), array( 'lang' => 'fr-BE' ) ); ?></td>
				<td>
					<?php if ( ! $bps_new ) : ?>
						<input type="checkbox" id="<?php echo esc_attr( P::id( array_merge( $bps_base, array( 'remove' ) ) ) ); ?>" name="<?php echo esc_attr( P::name( array_merge( $bps_base, array( 'remove' ) ) ) ); ?>" value="1">
						<label for="<?php echo esc_attr( P::id( array_merge( $bps_base, array( 'remove' ) ) ) ); ?>" class="screen-reader-text"><?php esc_html_e( 'Verwijderen', 'borduur-patch-studio' ); ?></label>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<button type="button" class="button" data-bps-add-row="#bps-<?php echo esc_attr( $bps_key ); ?>"><?php esc_html_e( '+ Nog een kleur', 'borduur-patch-studio' ); ?></button>
</section>
<?php endforeach; ?>
