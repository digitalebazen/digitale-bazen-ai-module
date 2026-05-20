<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var bool $acf_active
 * @var bool $field_group_found
 */
?>
<div class="wrap db-ai-wrap">
	<h1>
		<?php esc_html_e( 'AI Blog Genereren', 'digitale-bazen-ai-module' ); ?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . DB_AI_Settings::PAGE_SLUG ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Instellingen', 'digitale-bazen-ai-module' ); ?>
		</a>
	</h1>

	<?php if ( ! $acf_active ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'ACF Pro is niet actief. Activeer ACF Pro om deze plugin te gebruiken.', 'digitale-bazen-ai-module' ); ?></p>
		</div>
	<?php elseif ( ! $field_group_found ) : ?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'Geen ACF field group met een flexible content veld gevonden op deze site. Maak er één aan (of importeer een bestaande), en kies hem daarna in Instellingen → AI Module → ACF integratie.', 'digitale-bazen-ai-module' ); ?>
			</p>
		</div>
	<?php else : ?>

		<div class="db-ai-step">
			<h2><?php esc_html_e( '1. Upload zoekwoordenonderzoek', 'digitale-bazen-ai-module' ); ?></h2>
			<label for="db-ai-csv-file"><?php esc_html_e( 'Bestand', 'digitale-bazen-ai-module' ); ?></label>
			<input type="file" id="db-ai-csv-file" accept=".csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.oasis.opendocument.spreadsheet">
			<p class="description">
				<?php esc_html_e( 'Accepteert .xlsx, .xls, .csv en .ods. Kolommen worden automatisch herkend; je krijgt anders een mapping-stap. Vereist: een kolom met zoekwoorden. Optioneel: Maandelijks volume, Pagina, Onderwerp, Concurrentie, CPC Laag, CPC hoog.', 'digitale-bazen-ai-module' ); ?>
				<a href="#" id="db-ai-template-download" class="db-ai-template-link"><?php esc_html_e( 'Download lege template (.csv)', 'digitale-bazen-ai-module' ); ?></a>
			</p>
			<div id="db-ai-status" class="db-ai-status" role="status" aria-live="polite"></div>

			<div id="db-ai-mapping" class="db-ai-mapping" hidden>
				<h3><?php esc_html_e( 'Kolom-mapping', 'digitale-bazen-ai-module' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Koppel de kolommen uit jouw bestand aan het verwachte schema. Auto-detectie is alvast ingevuld; pas aan waar nodig.', 'digitale-bazen-ai-module' ); ?></p>
				<table class="db-ai-mapping-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Doelveld', 'digitale-bazen-ai-module' ); ?></th>
							<th><?php esc_html_e( 'Bron-kolom', 'digitale-bazen-ai-module' ); ?></th>
						</tr>
					</thead>
					<tbody id="db-ai-mapping-rows"></tbody>
				</table>
				<details class="db-ai-mapping-preview">
					<summary><?php esc_html_e( 'Preview eerste 5 rijen', 'digitale-bazen-ai-module' ); ?></summary>
					<div id="db-ai-mapping-preview-table"></div>
				</details>
				<p>
					<button type="button" id="db-ai-mapping-apply" class="button button-primary">
						<?php esc_html_e( 'Mapping toepassen en doorgaan', 'digitale-bazen-ai-module' ); ?>
					</button>
					<button type="button" id="db-ai-mapping-cancel" class="button">
						<?php esc_html_e( 'Annuleer', 'digitale-bazen-ai-module' ); ?>
					</button>
				</p>
			</div>
		</div>

		<div class="db-ai-step">
			<h2><?php esc_html_e( '2. Kies hoofdzoekwoord', 'digitale-bazen-ai-module' ); ?></h2>
			<label for="db-ai-keyword-select"><?php esc_html_e( 'Zoekwoord', 'digitale-bazen-ai-module' ); ?></label>
			<select id="db-ai-keyword-select" disabled>
				<option value=""><?php esc_html_e( 'Upload eerst een CSV', 'digitale-bazen-ai-module' ); ?></option>
			</select>
			<div id="db-ai-secondary-preview"></div>
		</div>

		<div class="db-ai-step">
			<h2><?php esc_html_e( '3. Genereer blogpost', 'digitale-bazen-ai-module' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Roept de AI aan, downloadt afbeeldingen, maakt een draft aan onder Blogs. Duurt 30-60 seconden.', 'digitale-bazen-ai-module' ); ?>
			</p>
			<button type="button" id="db-ai-generate-btn" class="button button-primary" disabled>
				<?php esc_html_e( 'Genereer blogpost', 'digitale-bazen-ai-module' ); ?>
			</button>
			<span id="db-ai-quota" class="db-ai-quota"></span>
			<div id="db-ai-generate-status" class="db-ai-status" role="status" aria-live="polite"></div>
			<div id="db-ai-generate-result" class="db-ai-result"></div>
		</div>

	<?php endif; ?>
</div>
