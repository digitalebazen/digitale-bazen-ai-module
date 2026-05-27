<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var bool $acf_active
 * @var bool $field_group_found
 */

$saved_kwos = class_exists( 'DB_AI_Keyword_Research' )
	? DB_AI_Keyword_Research::get_all()
	: [];

$internal_links_enabled = class_exists( 'DB_AI_Internal_Links' ) && DB_AI_Internal_Links::is_enabled();
$link_post_types        = $internal_links_enabled ? DB_AI_Internal_Links::get_post_types() : [];
$link_candidates        = [];
if ( $internal_links_enabled && ! empty( $link_post_types ) ) {
	$link_candidates = get_posts(
		[
			'post_type'        => $link_post_types,
			'post_status'      => 'publish',
			'numberposts'      => 100,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => false,
		]
	);
}
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

		<div class="db-ai-wizard">
			<ol class="db-ai-wizard-progress">
				<li data-step="1" class="is-enabled is-active">
					<span class="step-circle"><span class="step-number">1</span></span>
					<?php esc_html_e( 'Upload', 'digitale-bazen-ai-module' ); ?>
				</li>
				<span class="step-connector"></span>
				<li data-step="2">
					<span class="step-circle"><span class="step-number">2</span></span>
					<?php esc_html_e( 'Kies zoekwoord', 'digitale-bazen-ai-module' ); ?>
				</li>
				<span class="step-connector"></span>
				<li data-step="3">
					<span class="step-circle"><span class="step-number">3</span></span>
					<?php esc_html_e( 'Genereer', 'digitale-bazen-ai-module' ); ?>
				</li>
			</ol>

			<section class="db-ai-wizard-pane is-active" data-step="1">
				<h2><?php esc_html_e( '1. Kies of upload zoekwoordenonderzoek', 'digitale-bazen-ai-module' ); ?></h2>

				<?php if ( ! empty( $saved_kwos ) ) : ?>
					<div class="db-ai-kwo-picker">
						<label for="db-ai-kwo-select"><?php esc_html_e( 'Gebruik opgeslagen onderzoek', 'digitale-bazen-ai-module' ); ?></label>
						<select id="db-ai-kwo-select">
							<option value=""><?php esc_html_e( '— Kies een onderzoek —', 'digitale-bazen-ai-module' ); ?></option>
							<?php foreach ( $saved_kwos as $r ) : ?>
								<option value="<?php echo (int) $r['id']; ?>">
									<?php
									/* translators: 1 = onderzoek naam, 2 = aantal zoekwoorden, 3 = upload-datum */
									printf(
										esc_html__( '%1$s (%2$d zoekwoorden — %3$s)', 'digitale-bazen-ai-module' ),
										esc_html( $r['name'] ),
										(int) $r['count'],
										esc_html( $r['uploaded_at'] )
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							printf(
								/* translators: %s = link naar Settings */
								wp_kses(
									__( 'Beheer (toevoegen / verwijderen) van onderzoeken doe je in %s. Of <a href="#" id="db-ai-kwo-toggle-upload">upload hieronder eenmalig</a> zonder opslaan.', 'digitale-bazen-ai-module' ),
									[ 'a' => [ 'href' => [], 'id' => [] ] ]
								),
								'<a href="' . esc_url( admin_url( 'options-general.php?page=' . DB_AI_Settings::PAGE_SLUG . '#kwo' ) ) . '">' . esc_html__( 'Instellingen → Zoekwoorden', 'digitale-bazen-ai-module' ) . '</a>'
							);
							?>
						</p>
						<div id="db-ai-kwo-load-status" class="db-ai-status" role="status" aria-live="polite"></div>
					</div>
				<?php endif; ?>

				<div class="db-ai-csv-upload-wrap"<?php echo empty( $saved_kwos ) ? '' : ' hidden'; ?>>
					<?php if ( empty( $saved_kwos ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s = link naar Settings */
								wp_kses(
									__( '<strong>Tip:</strong> upload onderzoeken in %s om ze hier in één klik te kiezen zonder opnieuw te uploaden.', 'digitale-bazen-ai-module' ),
									[ 'a' => [ 'href' => [] ], 'strong' => [] ]
								),
								'<a href="' . esc_url( admin_url( 'options-general.php?page=' . DB_AI_Settings::PAGE_SLUG . '#kwo' ) ) . '">' . esc_html__( 'Instellingen → Zoekwoorden', 'digitale-bazen-ai-module' ) . '</a>'
							);
							?>
						</p>
					<?php endif; ?>
					<label for="db-ai-csv-file"><?php esc_html_e( 'Of upload eenmalig een bestand', 'digitale-bazen-ai-module' ); ?></label>
					<input type="file" id="db-ai-csv-file" accept=".csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.oasis.opendocument.spreadsheet">
					<p class="description">
						<?php esc_html_e( 'Accepteert .xlsx, .xls, .csv en .ods. Kolommen worden automatisch herkend; je krijgt anders een mapping-stap. Vereist: een kolom met zoekwoorden. Optioneel: Maandelijks volume, Pagina, Onderwerp, Concurrentie, CPC Laag, CPC hoog.', 'digitale-bazen-ai-module' ); ?>
						<a href="#" id="db-ai-template-download" class="db-ai-template-link"><?php esc_html_e( 'Download lege template (.csv)', 'digitale-bazen-ai-module' ); ?></a>
					</p>
					<div id="db-ai-status" class="db-ai-status" role="status" aria-live="polite"></div>
				</div>

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
			</section>

			<section class="db-ai-wizard-pane" data-step="2">
				<h2><?php esc_html_e( '2. Kies hoofdzoekwoord', 'digitale-bazen-ai-module' ); ?></h2>
				<label for="db-ai-keyword-select"><?php esc_html_e( 'Zoekwoord', 'digitale-bazen-ai-module' ); ?></label>
				<select id="db-ai-keyword-select" disabled>
					<option value=""><?php esc_html_e( 'Upload eerst een CSV', 'digitale-bazen-ai-module' ); ?></option>
				</select>
				<div id="db-ai-secondary-preview"></div>
			</section>

			<section class="db-ai-wizard-pane" data-step="3">
				<h2><?php esc_html_e( '3. Genereer blogpost', 'digitale-bazen-ai-module' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Klaar om te genereren? Klik de knop en de generator schrijft je blog. Duurt 30-60 seconden. Wil je extra sturing geven? Klap "Geavanceerd" open.', 'digitale-bazen-ai-module' ); ?>
				</p>

				<details class="db-ai-advanced-toggle">
					<summary><?php esc_html_e( 'Geavanceerd (optioneel)', 'digitale-bazen-ai-module' ); ?></summary>
					<div class="db-ai-advanced-content">

						<div class="db-ai-field-wrap">
							<label for="db-ai-funnel-phase"><?php esc_html_e( 'Funnel-fase', 'digitale-bazen-ai-module' ); ?></label>
							<select id="db-ai-funnel-phase">
								<option value=""><?php esc_html_e( '— Auto —', 'digitale-bazen-ai-module' ); ?></option>
								<option value="tofu"><?php esc_html_e( 'Top of funnel (lezer ontdekt probleem)', 'digitale-bazen-ai-module' ); ?></option>
								<option value="mofu"><?php esc_html_e( 'Middle of funnel (lezer overweegt oplossingen)', 'digitale-bazen-ai-module' ); ?></option>
								<option value="bofu"><?php esc_html_e( 'Bottom of funnel (lezer kiest aanbieder)', 'digitale-bazen-ai-module' ); ?></option>
							</select>
						</div>

						<div class="db-ai-field-wrap">
							<label for="db-ai-awareness-level"><?php esc_html_e( 'Awareness-niveau', 'digitale-bazen-ai-module' ); ?></label>
							<select id="db-ai-awareness-level">
								<option value=""><?php esc_html_e( '— Auto —', 'digitale-bazen-ai-module' ); ?></option>
								<option value="unaware"><?php esc_html_e( 'Onbekend met probleem', 'digitale-bazen-ai-module' ); ?></option>
								<option value="problem"><?php esc_html_e( 'Kent probleem, geen oplossing', 'digitale-bazen-ai-module' ); ?></option>
								<option value="solution"><?php esc_html_e( 'Kent oplossingen, vergelijkt', 'digitale-bazen-ai-module' ); ?></option>
								<option value="product"><?php esc_html_e( 'Kent jouw aanbod, twijfelt nog', 'digitale-bazen-ai-module' ); ?></option>
							</select>
						</div>

						<div class="db-ai-field-wrap">
							<label for="db-ai-must-include"><?php esc_html_e( 'Belangrijke punten die benoemd moeten worden', 'digitale-bazen-ai-module' ); ?></label>
							<textarea
								id="db-ai-must-include"
								rows="3"
								class="large-text"
								placeholder="<?php echo esc_attr__( "Bv:\n- Wij doen alleen WordPress, geen Shopify\n- Wij hebben een vaste prijs per maand\n- 24/7 support inbegrepen", 'digitale-bazen-ai-module' ); ?>"
							></textarea>
						</div>

						<div class="db-ai-field-wrap">
							<label for="db-ai-must-avoid"><?php esc_html_e( 'Onderwerpen / claims die vermeden moeten worden', 'digitale-bazen-ai-module' ); ?></label>
							<textarea
								id="db-ai-must-avoid"
								rows="3"
								class="large-text"
								placeholder="<?php echo esc_attr__( "Bv:\n- Geen prijsindicaties\n- Geen vergelijkingen met specifieke concurrenten\n- Geen beloften over leadtimes", 'digitale-bazen-ai-module' ); ?>"
							></textarea>
						</div>

						<div class="db-ai-field-wrap">
							<label for="db-ai-beat-competition"><?php esc_html_e( 'Wat moet deze blog beter doen dan concurrenten?', 'digitale-bazen-ai-module' ); ?></label>
							<textarea
								id="db-ai-beat-competition"
								rows="3"
								class="large-text"
								placeholder="<?php echo esc_attr__( "Bv:\n- Duidelijker uitleggen, minder vakjargon\n- Praktijkgerichter, met concrete checklist\n- Sneller tot de kern, geen lange inleidingen", 'digitale-bazen-ai-module' ); ?>"
							></textarea>
							<p class="description"><?php esc_html_e( 'Bestaande top-3 in Google voor dit zoekwoord doet meestal iets standaards. Wat is jouw edge?', 'digitale-bazen-ai-module' ); ?></p>
						</div>

						<?php if ( $internal_links_enabled && ! empty( $link_candidates ) ) : ?>
							<div class="db-ai-field-wrap">
								<label for="db-ai-forced-links"><?php esc_html_e( 'Verplicht linken naar', 'digitale-bazen-ai-module' ); ?></label>
								<select
									id="db-ai-forced-links"
									multiple
									size="8"
									style="width:100%;max-width:560px;"
								>
									<?php foreach ( $link_candidates as $candidate ) : ?>
										<option value="<?php echo (int) $candidate->ID; ?>">
											[<?php echo esc_html( $candidate->post_type ); ?>] <?php echo esc_html( $candidate->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Cmd/Ctrl + klik om meerdere te selecteren. Deze pagina\'s krijgen voorrang — de generator probeert ze te plaatsen waar ze passen, boven de automatische pool.', 'digitale-bazen-ai-module' ); ?>
								</p>
							</div>
						<?php endif; ?>

						<div class="db-ai-field-wrap">
							<label for="db-ai-extra-instructions"><?php esc_html_e( 'Extra instructies voor deze blog', 'digitale-bazen-ai-module' ); ?></label>
							<textarea
								id="db-ai-extra-instructions"
								rows="4"
								class="large-text"
								placeholder="<?php echo esc_attr__( "Bv: focus op praktische voorbeelden, vermijd technische termen, benoem onze 24/7 support, sluit af met een vergelijking met DIY-aanpak.", 'digitale-bazen-ai-module' ); ?>"
							></textarea>
							<p class="description"><?php esc_html_e( 'Aanwijzingen voor deze ene blog die je niet kwijt wilt in de algemene instellingen. Wordt aan de prompt toegevoegd.', 'digitale-bazen-ai-module' ); ?></p>
						</div>

					</div>
				</details>

				<button type="button" id="db-ai-generate-btn" class="button button-primary" disabled>
					<?php esc_html_e( 'Genereer blogpost', 'digitale-bazen-ai-module' ); ?>
				</button>
				<span id="db-ai-quota" class="db-ai-quota"></span>

				<div id="db-ai-generate-progress" class="db-ai-progress" hidden aria-live="polite" aria-atomic="true">
					<div class="db-ai-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
						<div class="db-ai-progress__fill"></div>
					</div>
					<div class="db-ai-progress__label"><?php esc_html_e( 'Voorbereiden…', 'digitale-bazen-ai-module' ); ?></div>
				</div>

				<div id="db-ai-generate-status" class="db-ai-status" role="status" aria-live="polite"></div>
				<div id="db-ai-generate-result" class="db-ai-result"></div>
			</section>
		</div>

	<?php endif; ?>
</div>
