<?php
/**
 * Name tapes: admin page (Admin::render()).
 *
 * @package BorduurPatchStudio
 * @var array  $cfg      Merged config.
 * @var array  $status   Status rows.
 * @var array  $classes  Shipping classes slug => name.
 * @var array  $sample   Example quote (10 tapes).
 * @var string $msg      Notice code.
 * @var string $page_url Name-tape page URL or ''.
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

$bps_l     = static fn( $o ) => Pricing::label( $o, 'nl' );
$bps_money = static fn( $v ) => number_format( (float) $v, 2, ',', '.' );
$bps_on    = static fn( $e, string $k ) => false !== ( $e[ $k ] ?? true );
$bps_msgs  = array(
	'saved'        => array( 'success', __( 'Instellingen bewaard.', 'borduur-patch-studio' ) ),
	'saved_fixed'  => array( 'warning', __( 'Bewaard. Je had in een lijst alles uitgezet; de standaardkeuze staat daar weer aan, anders kan niemand bestellen.', 'borduur-patch-studio' ) ),
	'reset'        => array( 'success', __( 'Standaardwaarden hersteld.', 'borduur-patch-studio' ) ),
	'page_created' => array( 'success', __( 'De naamstrookpagina is aangemaakt.', 'borduur-patch-studio' ) ),
	'page_exists'  => array( 'info', __( 'Er bestaat al een naamstrookpagina.', 'borduur-patch-studio' ) ),
	'page_failed'  => array( 'error', __( 'De pagina kon niet worden aangemaakt.', 'borduur-patch-studio' ) ),
);
$bps_vat   = Config::vat_rate();
$bps_incl  = static fn( $excl ) => $bps_money( round( (float) $excl * ( 1 + $bps_vat ), 2 ) );
?>
<div class="wrap bps-nt-admin">
	<h1><?php esc_html_e( 'Patch Studio · Naamstroken', 'borduur-patch-studio' ); ?></h1>
	<p><?php esc_html_e( 'Alle bedragen hieronder zijn exclusief btw. De klant ziet altijd prijzen inclusief btw.', 'borduur-patch-studio' ); ?></p>

	<?php if ( isset( $bps_msgs[ $msg ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $bps_msgs[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $bps_msgs[ $msg ][1] ); ?></p></div>
	<?php endif; ?>

	<style>
		.bps-nt-admin .bps-nt-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:12px 16px; margin:16px 0; max-width:980px; }
		.bps-nt-admin .bps-nt-card h2 { margin-top:4px; }
		.bps-nt-admin table.widefat td, .bps-nt-admin table.widefat th { vertical-align:middle; }
		.bps-nt-admin .ok { color:#1d7a3a; font-weight:600; } .bps-nt-admin .ko { color:#b3202a; font-weight:600; }
		.bps-nt-admin .sw { display:inline-block; width:16px; height:16px; border-radius:3px; border:1px solid #0003; vertical-align:middle; margin-right:6px; }
		.bps-nt-admin input.small-text { width:80px; }
		.bps-nt-admin .bps-nt-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
	</style>

	<div class="bps-nt-card">
		<h2><?php esc_html_e( 'Status', 'borduur-patch-studio' ); ?></h2>
		<table class="widefat striped"><tbody>
		<?php foreach ( $status as $bps_s ) : ?>
			<tr>
				<th scope="row" style="width:220px"><?php echo esc_html( $bps_s['label'] ); ?></th>
				<td><span class="<?php echo $bps_s['ok'] ? 'ok' : 'ko'; ?>"><?php echo $bps_s['ok'] ? '✓' : '!'; ?></span>
					<?php if ( '' !== $bps_s['link'] ) : ?>
						<a href="<?php echo esc_url( $bps_s['link'] ); ?>"><?php echo esc_html( $bps_s['message'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $bps_s['message'] ); ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<h3><?php esc_html_e( 'Test de winkelmand (fictief, er wordt niets bewaard)', 'borduur-patch-studio' ); ?></h3>
		<?php if ( ! empty( $sample['ok'] ) ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: pieces, 2: unit incl, 3: line incl, 4: fees incl, 5: shipping incl, 6: total incl */
						__( '%1$d naamstroken: € %2$s/st · lijn € %3$s · instelkost € %4$s · verzending (schatting) € %5$s · totaal € %6$s (alles incl. btw).', 'borduur-patch-studio' ),
						(int) $sample['pieces'],
						$bps_money( $sample['unitIncl'] ),
						$bps_money( $sample['lineIncl'] ),
						$bps_money( $sample['feesIncl'] ),
						$bps_money( $sample['shippingIncl'] ),
						$bps_money( $sample['totalIncl'] )
					)
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Zie je in je echte winkelmand bij naamstroken een extra borduurkaart van € 45? Dan rekent een andere plugin (Conditional Extra Fees) die aan: sluit de categorie hierboven daar uit.', 'borduur-patch-studio' ); ?></p>
		<?php else : ?>
			<p class="ko"><?php echo esc_html( implode( ', ', array_map( 'strval', (array) ( $sample['errors'] ?? array() ) ) ) ); ?></p>
		<?php endif; ?>
	</div>

	<div class="bps-nt-card">
		<h2><?php esc_html_e( 'Snel aan de slag', 'borduur-patch-studio' ); ?></h2>
		<?php if ( '' !== $page_url ) : ?>
			<p><?php esc_html_e( 'Je naamstrookpagina:', 'borduur-patch-studio' ); ?> <a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $page_url ); ?></a></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( Admin::MAKEPAGE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Admin::MAKEPAGE ); ?>">
				<p><?php esc_html_e( 'Maakt een gepubliceerde pagina "Naamstroken met klittenband" met de shortcode [patch_studio_naamstroken]. Je kan ze daarna gewoon in je menu zetten.', 'borduur-patch-studio' ); ?></p>
				<?php submit_button( __( 'Maak de naamstrookpagina aan', 'borduur-patch-studio' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Shortcode-opties: formaat="12x3" (startformaat), stof="navy" (startstof), compact="yes".', 'borduur-patch-studio' ); ?></p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( Admin::SAVE ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Admin::SAVE ); ?>">

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Algemeen', 'borduur-patch-studio' ); ?></h2>
			<p><label><input type="checkbox" name="bps_nt[enabled]" value="1" <?php checked( Config::enabled() ); ?>> <?php esc_html_e( 'Naamstroken verkopen (uit = pagina toont niets, nieuwe lijsten worden geweigerd)', 'borduur-patch-studio' ); ?></label></p>
			<p><label><input type="checkbox" name="bps_nt[text][uppercaseDefault]" value="1" <?php checked( false !== ( $cfg['text']['uppercaseDefault'] ?? true ) ); ?>> <?php esc_html_e( 'Hoofdletters standaard aan', 'borduur-patch-studio' ); ?></label></p>
			<p><label><?php esc_html_e( 'Maximum aantal namen per lijst', 'borduur-patch-studio' ); ?> <input class="small-text" type="number" min="10" max="500" name="bps_nt[maxRows]" value="<?php echo esc_attr( (string) (int) ( $cfg['maxRows'] ?? 250 ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Notatie bloedgroep', 'borduur-patch-studio' ); ?>
				<select name="bps_nt[blood][notation]">
					<?php foreach ( array( 'O POS', 'O+' ) as $bps_n ) : ?>
						<option value="<?php echo esc_attr( $bps_n ); ?>" <?php selected( (string) ( $cfg['blood']['notation'] ?? 'O POS' ), $bps_n ); ?>><?php echo esc_html( $bps_n ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<p><label><input type="checkbox" name="bps_nt[irOnRequest]" value="1" <?php checked( ! empty( $cfg['irOnRequest'] ) ); ?>> <?php esc_html_e( 'Toon de zin "IR-reflecterend op aanvraag voor professionelen"', 'borduur-patch-studio' ); ?></label></p>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Formaten & prijzen', 'borduur-patch-studio' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Aan', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Formaat', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Regels', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Prijs/st excl. btw', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Klant ziet (incl.)', 'borduur-patch-studio' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $cfg['formats'] ?? array() ) as $bps_f ) : ?>
					<tr>
						<td><input type="checkbox" name="bps_nt[formats][<?php echo esc_attr( $bps_f['id'] ); ?>][enabled]" value="1" <?php checked( $bps_on( $bps_f, 'enabled' ) ); ?>></td>
						<td><?php echo esc_html( $bps_l( $bps_f['label'] ?? $bps_f['id'] ) ); ?></td>
						<td><?php echo (int) ( $bps_f['lines'] ?? 1 ); ?></td>
						<td>€ <input class="small-text" type="text" inputmode="decimal" name="bps_nt[formats][<?php echo esc_attr( $bps_f['id'] ); ?>][priceExcl]" value="<?php echo esc_attr( $bps_money( $bps_f['priceExcl'] ?? 0 ) ); ?>"></td>
						<td>€ <?php echo esc_html( $bps_incl( $bps_f['priceExcl'] ?? 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><label><input type="checkbox" name="bps_nt[customSize][enabled]" value="1" <?php checked( ! empty( $cfg['customSize']['enabled'] ) ); ?>> <?php esc_html_e( '"Andere maat" aanbieden (prijs volgens de tabel in nametape.json)', 'borduur-patch-studio' ); ?></label></p>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Stoffen', 'borduur-patch-studio' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Op voorraad', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Stof', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Toeslag/st excl. btw', 'borduur-patch-studio' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $cfg['fabrics'] ?? array() ) as $bps_f ) : ?>
					<tr>
						<td><input type="checkbox" name="bps_nt[fabrics][<?php echo esc_attr( $bps_f['id'] ); ?>][inStock]" value="1" <?php checked( $bps_on( $bps_f, 'inStock' ) ); ?>></td>
						<td><span class="sw" style="background:<?php echo esc_attr( (string) ( $bps_f['hex'] ?? '#888' ) ); ?>"></span><?php echo esc_html( $bps_l( $bps_f['label'] ?? $bps_f['id'] ) ); ?></td>
						<td>€ <input class="small-text" type="text" inputmode="decimal" name="bps_nt[fabrics][<?php echo esc_attr( $bps_f['id'] ); ?>][surchargeExcl]" value="<?php echo esc_attr( $bps_money( $bps_f['surchargeExcl'] ?? 0 ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Een snelle stijl met een stof die niet op voorraad is, verdwijnt automatisch.', 'borduur-patch-studio' ); ?></p>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Letterkleuren, lettertypes, randen', 'borduur-patch-studio' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Aan', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Letterkleur', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Garencode (voor de productielijst)', 'borduur-patch-studio' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $cfg['letterColors'] ?? array() ) as $bps_c ) : ?>
					<tr>
						<td><input type="checkbox" name="bps_nt[letterColors][<?php echo esc_attr( $bps_c['id'] ); ?>][enabled]" value="1" <?php checked( $bps_on( $bps_c, 'enabled' ) ); ?>></td>
						<td><span class="sw" style="background:<?php echo esc_attr( (string) ( $bps_c['hex'] ?? '#888' ) ); ?>"></span><?php echo esc_html( $bps_l( $bps_c['label'] ?? $bps_c['id'] ) ); ?></td>
						<td><input type="text" class="regular-text" maxlength="40" name="bps_nt[letterColors][<?php echo esc_attr( $bps_c['id'] ); ?>][threadCode]" value="<?php echo esc_attr( (string) ( $bps_c['threadCode'] ?? '' ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<strong><?php esc_html_e( 'Lettertypes:', 'borduur-patch-studio' ); ?></strong>
				<?php foreach ( (array) ( $cfg['fonts'] ?? array() ) as $bps_f ) : ?>
					<label style="margin-right:12px"><input type="checkbox" name="bps_nt[fonts][<?php echo esc_attr( $bps_f['id'] ); ?>][enabled]" value="1" <?php checked( $bps_on( $bps_f, 'enabled' ) ); ?>> <?php echo esc_html( $bps_l( $bps_f['label'] ?? $bps_f['id'] ) . ' (' . ( $bps_f['family'] ?? '' ) . ')' ); ?></label>
				<?php endforeach; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Randen:', 'borduur-patch-studio' ); ?></strong>
				<?php foreach ( (array) ( $cfg['borders'] ?? array() ) as $bps_b ) : ?>
					<label style="margin-right:12px"><input type="checkbox" name="bps_nt[borders][<?php echo esc_attr( $bps_b['id'] ); ?>][enabled]" value="1" <?php checked( $bps_on( $bps_b, 'enabled' ) ); ?>> <?php echo esc_html( $bps_l( $bps_b['label'] ?? $bps_b['id'] ) ); ?></label>
				<?php endforeach; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Een stencil- of militair lettertype voegen we toe zodra je bevestigt welke borduurlettertypes je software heeft.', 'borduur-patch-studio' ); ?></p>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Achterkant & tegenstuk', 'borduur-patch-studio' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Aan', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Achterkant', 'borduur-patch-studio' ); ?></th><th><?php esc_html_e( 'Verschil/st excl. btw', 'borduur-patch-studio' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $cfg['backings'] ?? array() ) as $bps_b ) : ?>
					<tr>
						<td><input type="checkbox" name="bps_nt[backings][<?php echo esc_attr( $bps_b['id'] ); ?>][enabled]" value="1" <?php checked( $bps_on( $bps_b, 'enabled' ) ); ?>></td>
						<td><?php echo esc_html( $bps_l( $bps_b['label'] ?? $bps_b['id'] ) ); ?></td>
						<td>€ <input class="small-text" type="text" inputmode="decimal" name="bps_nt[backings][<?php echo esc_attr( $bps_b['id'] ); ?>][deltaExcl]" value="<?php echo esc_attr( $bps_money( $bps_b['deltaExcl'] ?? 0 ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label><input type="checkbox" name="bps_nt[loopCounterpart][enabled]" value="1" <?php checked( ! empty( $cfg['loopCounterpart']['enabled'] ) ); ?>> <?php esc_html_e( 'Zacht tegenstuk (lusband) aanbieden bij klittenband, prijs/st excl. btw', 'borduur-patch-studio' ); ?></label>
				€ <input class="small-text" type="text" inputmode="decimal" name="bps_nt[loopCounterpart][priceExcl]" value="<?php echo esc_attr( $bps_money( $cfg['loopCounterpart']['priceExcl'] ?? 1 ) ); ?>">
			</p>
			<p class="description"><?php esc_html_e( 'Het vinkje staat bij de klant nooit vooraf aan.', 'borduur-patch-studio' ); ?></p>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Staffel & instelkost', 'borduur-patch-studio' ); ?></h2>
			<p><label><input type="checkbox" name="bps_nt[tiers][pooledWithPatches]" value="1" <?php checked( false !== ( $cfg['tiers']['pooledWithPatches'] ?? true ) ); ?>> <?php esc_html_e( 'Naamstroken tellen mee in de staffel van de patches (en omgekeerd)', 'borduur-patch-studio' ); ?></label></p>
			<p class="description"><?php esc_html_e( 'De staffelstappen zelf komen uit de algemene Patch Studio-instellingen.', 'borduur-patch-studio' ); ?></p>
			<p>
				<label><input type="checkbox" name="bps_nt[smallOrderFee][enabled]" value="1" <?php checked( ! empty( $cfg['smallOrderFee']['enabled'] ) ); ?>> <?php esc_html_e( 'Instelkost aanrekenen onder', 'borduur-patch-studio' ); ?></label>
				<input class="small-text" type="number" min="1" max="100" name="bps_nt[smallOrderFee][belowQty]" value="<?php echo esc_attr( (string) (int) ( $cfg['smallOrderFee']['belowQty'] ?? 5 ) ); ?>">
				<?php esc_html_e( 'stuks, bedrag excl. btw', 'borduur-patch-studio' ); ?>
				€ <input class="small-text" type="text" inputmode="decimal" name="bps_nt[smallOrderFee][amountExcl]" value="<?php echo esc_attr( $bps_money( $cfg['smallOrderFee']['amountExcl'] ?? 7.5 ) ); ?>">
			</p>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Controlewoorden', 'borduur-patch-studio' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Eén woord of woordgroep per regel. Staat er een in een naam of regel 2, dan krijg je een ordernotitie "Manuele controle nodig". De klant wordt nooit geblokkeerd.', 'borduur-patch-studio' ); ?></p>
			<textarea name="bps_nt[reviewWords]" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", array_map( 'strval', (array) ( $cfg['reviewWords'] ?? array() ) ) ) ); ?></textarea>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Beloftes', 'borduur-patch-studio' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Leeg = we beloven niets. Beloof enkel wat je altijd waarmaakt.', 'borduur-patch-studio' ); ?></p>
			<?php $bps_lead = $cfg['promises']['leadTime'] ?? ''; ?>
			<p><label><input type="checkbox" name="bps_nt[promises][proof]" value="1" <?php checked( ! empty( $cfg['promises']['proof'] ) ); ?>> <?php esc_html_e( 'Toon: "Je krijgt een digitale proef met alle namen voor we borduren"', 'borduur-patch-studio' ); ?></label></p>
			<p><label><?php esc_html_e( 'Levertijd (NL)', 'borduur-patch-studio' ); ?><br><input type="text" class="large-text" maxlength="160" name="bps_nt[promises][leadTime][nl]" value="<?php echo esc_attr( is_array( $bps_lead ) ? (string) ( $bps_lead['nl'] ?? '' ) : (string) $bps_lead ); ?>" placeholder="<?php esc_attr_e( 'bv. Klaar binnen 5 werkdagen na je akkoord op de proef', 'borduur-patch-studio' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Levertijd (FR)', 'borduur-patch-studio' ); ?><br><input type="text" class="large-text" maxlength="160" name="bps_nt[promises][leadTime][fr]" value="<?php echo esc_attr( is_array( $bps_lead ) ? (string) ( $bps_lead['fr'] ?? '' ) : '' ); ?>" placeholder="ex. Prêt en 5 jours ouvrables après votre accord sur l’épreuve"></label></p>
		</div>

		<div class="bps-nt-card">
			<h2><?php esc_html_e( 'Verzendklasse', 'borduur-patch-studio' ); ?></h2>
			<p><select name="bps_nt[woo][shippingClassSlug]">
				<option value=""><?php esc_html_e( '— geen (standaard verzending) —', 'borduur-patch-studio' ); ?></option>
				<?php foreach ( $classes as $bps_slug => $bps_name ) : ?>
					<option value="<?php echo esc_attr( $bps_slug ); ?>" <?php selected( (string) ( $cfg['woo']['shippingClassSlug'] ?? '' ), $bps_slug ); ?>><?php echo esc_html( $bps_name ); ?></option>
				<?php endforeach; ?>
			</select></p>
			<p class="description"><?php esc_html_e( 'Naamstroken passen in een brievenbus. Maak eventueel een brievenbustarief aan in WooCommerce › Instellingen › Verzending › Klassen en kies het hier.', 'borduur-patch-studio' ); ?></p>
		</div>

		<?php submit_button( __( 'Instellingen bewaren', 'borduur-patch-studio' ) ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Alle naamstrook-instellingen terugzetten naar de standaard?', 'borduur-patch-studio' ) ); ?>');">
		<?php wp_nonce_field( Admin::RESET ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Admin::RESET ); ?>">
		<?php submit_button( __( 'Herstel standaard', 'borduur-patch-studio' ), 'link-delete', 'submit', false ); ?>
	</form>
</div>
