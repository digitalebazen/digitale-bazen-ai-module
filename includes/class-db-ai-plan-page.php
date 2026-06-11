<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Het Plan-submenu (v3.0.0, §0N/§0O / §14 Stap 8c).
 *
 * Toont het contentplan van het actieve zoekwoordenonderzoek (gegroepeerd per
 * cluster, pillar-eerst), met een "Analyseer onderzoek"-knop die DB_AI_Planner
 * via de job-queue draait. De worker-handler (run_plan_job) is static zodat hij
 * ook in de non-admin worker-context beschikbaar is.
 */
class DB_AI_Plan_Page {

	public const MENU_SLUG = 'db-ai-plan';

	/** job_type voor de async planner-run. */
	public const JOB_TYPE = 'plan';

	private $page_hook = '';

	// ─── Registratie ───────────────────────────────────────────────────────

	public function register(): void {
		// Priority 10: tussen Creatie (9) en Instellingen (11) → menu-volgorde
		// Creatie → Plan → Instellingen.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 10 );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_action( 'wp_ajax_db_ai_analyze_plan', [ $this, 'analyze_plan' ] );
		add_action( 'wp_ajax_db_ai_update_angle', [ $this, 'update_angle' ] );
		add_action( 'wp_ajax_db_ai_import_plan', [ $this, 'import_plan' ] );
		add_action( 'admin_post_db_ai_export_plan', [ $this, 'export_plan' ] );
	}

	/** De job-handler + publish-hook registreren — los van is_admin() (worker/publish draait non-admin). */
	public static function register_worker(): void {
		DB_AI_Job_Queue::register_handler( self::JOB_TYPE, [ __CLASS__, 'run_plan_job' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition_post_status' ], 10, 3 );
	}

	public function register_menu(): void {
		$parent     = (string) apply_filters( 'db_ai_admin_menu_parent', DB_AI_Admin_Page::MENU_SLUG );
		$capability = (string) apply_filters( 'db_ai_admin_menu_capability', 'publish_posts' );

		$this->page_hook = (string) add_submenu_page(
			$parent,
			__( 'Contentplan', 'digitale-bazen-ai-module' ),
			__( 'Plan', 'digitale-bazen-ai-module' ),
			$capability,
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	public function maybe_enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		$css_path = DB_AI_PLUGIN_DIR . 'assets/plan.css';
		$js_path  = DB_AI_PLUGIN_DIR . 'assets/plan.js';
		$css_ver  = file_exists( $css_path ) ? DB_AI_VERSION . '.' . filemtime( $css_path ) : DB_AI_VERSION;
		$js_ver   = file_exists( $js_path ) ? DB_AI_VERSION . '.' . filemtime( $js_path ) : DB_AI_VERSION;

		wp_enqueue_style( 'db-ai-plan', DB_AI_PLUGIN_URL . 'assets/plan.css', [], $css_ver );
		wp_enqueue_script( 'db-ai-plan', DB_AI_PLUGIN_URL . 'assets/plan.js', [], $js_ver, true );

		wp_localize_script(
			'db-ai-plan',
			'dbAiPlan',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( DB_AI_Ajax::NONCE_ACTION ),
				'i18n'    => [
					'analyzing'     => __( 'Onderzoek analyseren…', 'digitale-bazen-ai-module' ),
					'done'          => __( 'Plan klaar — pagina wordt ververst.', 'digitale-bazen-ai-module' ),
					'failed'        => __( 'Analyse mislukt.', 'digitale-bazen-ai-module' ),
					'networkError'  => __( 'Netwerkfout.', 'digitale-bazen-ai-module' ),
					'importConfirm' => __( 'Dit vervangt het huidige onderzoek en plan. Doorgaan?', 'digitale-bazen-ai-module' ),
					'importing'     => __( 'Importeren…', 'digitale-bazen-ai-module' ),
					'importOk'      => __( 'Geïmporteerd — pagina wordt ververst.', 'digitale-bazen-ai-module' ),
					'importFailed'  => __( 'Import mislukt.', 'digitale-bazen-ai-module' ),
				],
			]
		);
	}

	// ─── UI ────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'digitale-bazen-ai-module' ) );
		}

		$current = DB_AI_Keyword_Research::get_current();
		?>
		<div class="wrap db-ai-plan-wrap">
			<h1><?php esc_html_e( 'Contentplan', 'digitale-bazen-ai-module' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'De generator zet je zoekwoordenonderzoek om in een plan: per zoekwoord de zoekintentie, clusters met een pillar (die synoniemen bundelt) en supporting-artikelen. Genereer eerst de pillar van een cluster, daarna de supporting-artikelen.', 'digitale-bazen-ai-module' ); ?>
			</p>

			<?php if ( ! $current ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: %s = link naar Instellingen → Zoekwoorden */
						wp_kses( __( 'Er is nog geen zoekwoordenonderzoek. Upload er eerst één bij %s, of importeer hieronder een eerder geëxporteerd plan.', 'digitale-bazen-ai-module' ), [ 'a' => [ 'href' => [] ] ] ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . DB_AI_Settings::PAGE_SLUG . '#kwo' ) ) . '">' . esc_html__( 'Instellingen → Zoekwoorden', 'digitale-bazen-ai-module' ) . '</a>'
					);
					?>
				</p></div>
				<?php echo $this->import_export_controls( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div><?php return; ?>
			<?php endif; ?>

			<?php
			$plan = DB_AI_Keyword_Research::get_plan( (int) $current['id'] );
			// Repareer een opgeslagen plan waarin een cluster geen pillar heeft (kan door
			// batch-verwerking ontstaan) — gratis, zonder her-analyse: de hoogste-volume
			// entry van dat cluster wordt de pillar zodat het niet muurvast zit.
			if ( ! empty( $plan ) ) {
				$repaired = DB_AI_Planner::reconcile_pillars( $plan );
				if ( $repaired !== $plan ) {
					DB_AI_Keyword_Research::save_plan( (int) $current['id'], $repaired );
					$plan = $repaired;
				}
			}
			$plan_at = get_post_meta( (int) $current['id'], DB_AI_Keyword_Research::META_PLAN_AT, true );
			?>

			<div class="db-ai-plan-toolbar">
				<p>
					<strong><?php esc_html_e( 'Onderzoek:', 'digitale-bazen-ai-module' ); ?></strong>
					<?php echo esc_html( $current['name'] ); ?>
					<span class="db-ai-plan-meta">(<?php echo (int) $current['count']; ?> <?php esc_html_e( 'zoekwoorden', 'digitale-bazen-ai-module' ); ?><?php
						if ( $plan_at ) {
							echo ' — ' . esc_html__( 'plan van', 'digitale-bazen-ai-module' ) . ' ' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', (string) $plan_at ) );
						}
					?>)</span>
				</p>
				<p>
					<button type="button" id="db-ai-analyze-plan" class="button button-primary">
						<?php echo empty( $plan ) ? esc_html__( 'Analyseer onderzoek', 'digitale-bazen-ai-module' ) : esc_html__( 'Opnieuw analyseren', 'digitale-bazen-ai-module' ); ?>
					</button>
					<span id="db-ai-plan-status" class="db-ai-status" role="status" aria-live="polite"></span>
				</p>
				<p><?php echo $this->import_export_controls( ! empty( $plan ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</div>

			<?php
			if ( empty( $plan ) ) {
				echo '<p class="db-ai-plan-empty"><em>' . esc_html__( 'Nog geen plan. Klik op "Analyseer onderzoek" om het onderzoek te classificeren en te clusteren.', 'digitale-bazen-ai-module' ) . '</em></p>';
				echo '</div>';
				return;
			}

			$this->render_recommendations( $plan, (int) $current['id'] );
			$volumes = $this->build_volume_map( (int) $current['id'] );
			$this->render_plan( $plan, (int) $current['id'], $volumes );
			?>
		</div>
		<?php
	}

	/**
	 * Bepaal de aanbevolen volgende artikelen, transparant onderbouwd. Strategie
	 * (volgorde van prioriteit):
	 *  1. MAAK EEN GESTART CLUSTER AF — heb je de pillar al, dan eerst de supporting
	 *     erbij (sterkere topical authority + interne links) vóór je een nieuw cluster start.
	 *  2. START HET WAARDEVOLSTE CLUSTER — anders de pillar van het cluster met de
	 *     hoogste totale clusterwaarde (som van het zoekvolume in dat cluster).
	 *  3. Binnen die tiers: clusterwaarde → eigen zoekvolume → funnel-waarde (tiebreaker).
	 *  4. Pillar-eerst blijft: supporting van een nog-niet-gestart cluster wordt niet
	 *     aanbevolen (de pillar moet eerst bestaan).
	 *
	 * @return array<int,array{entry:array,reason:string}>
	 */
	private function recommendations( array $plan, int $limit = 5 ): array {
		// Cluster-stats: totale waarde (zoekvolume) + of de pillar al gemaakt is.
		$cluster_value = [];
		$pillar_done   = [];
		foreach ( $plan as $e ) {
			$role = (string) ( $e['role'] ?? '' );
			if ( 'pillar' !== $role && 'supporting' !== $role ) {
				continue;
			}
			$cluster = (string) ( $e['cluster'] ?? '' );
			if ( '' === $cluster ) {
				continue;
			}
			$cluster_value[ $cluster ] = ( $cluster_value[ $cluster ] ?? 0 ) + (int) ( $e['volume'] ?? 0 );
			if ( 'pillar' === $role ) {
				$done                    = in_array( (string) ( $e['status'] ?? '' ), [ 'gegenereerd', 'gepubliceerd' ], true );
				$pillar_done[ $cluster ] = ( $pillar_done[ $cluster ] ?? false ) || $done;
			}
		}

		$cands = [];
		foreach ( $plan as $e ) {
			$role = (string) ( $e['role'] ?? '' );
			if ( 'pillar' !== $role && 'supporting' !== $role ) {
				continue;
			}
			if ( 'open' !== (string) ( $e['status'] ?? 'open' ) ) {
				continue; // al gegenereerd/gepubliceerd
			}
			$cluster = (string) ( $e['cluster'] ?? '' );
			$volume  = (int) ( $e['volume'] ?? 0 );
			$funnel  = isset( $e['funnel_target'] ) && '' !== (string) $e['funnel_target'];
			$started = ! empty( $pillar_done[ $cluster ] );
			$cvalue  = (int) ( $cluster_value[ $cluster ] ?? 0 );

			if ( 'supporting' === $role && ! $started ) {
				continue; // pillar-eerst: nog geblokkeerd
			}

			// Tier 1 (start: gestart cluster afmaken) telt zwaarder dan tier 2 (nieuw cluster).
			$tier  = ( 'supporting' === $role && $started ) ? 1000000000 : 0;
			$score = $tier + ( $cvalue * 1000 ) + $volume + ( $funnel ? 250 : 0 );

			if ( 'supporting' === $role && $started ) {
				$reason = sprintf(
					/* translators: 1 = cluster, 2 = clusterwaarde, 3 = eigen volume */
					__( 'Maak cluster "%1$s" af (clusterwaarde %2$s/mnd) — de pillar staat al klaar; dit supporting-artikel (%3$s/mnd) versterkt de topical authority.', 'digitale-bazen-ai-module' ),
					$cluster,
					number_format_i18n( $cvalue ),
					number_format_i18n( $volume )
				);
			} else {
				$reason = sprintf(
					/* translators: 1 = cluster, 2 = clusterwaarde, 3 = pillar-volume */
					__( 'Start het cluster "%1$s" (clusterwaarde %2$s/mnd) — de pillar (%3$s/mnd) is de basis en ontgrendelt de supporting-artikelen.', 'digitale-bazen-ai-module' ),
					$cluster,
					number_format_i18n( $cvalue ),
					number_format_i18n( $volume )
				);
			}
			if ( $funnel ) {
				$reason .= ' ' . sprintf(
					/* translators: %s = dienst */
					__( 'Funnelt naar %s.', 'digitale-bazen-ai-module' ),
					(string) $e['funnel_target']
				);
			}

			$cands[] = [ 'entry' => $e, 'score' => $score, 'reason' => $reason ];
		}

		usort(
			$cands,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $cands, 0, max( 1, $limit ) );
	}

	/** Render het aanbevelingen-paneel bovenaan het plan. */
	private function render_recommendations( array $plan, int $research_id ): void {
		$recs = $this->recommendations( $plan );
		if ( empty( $recs ) ) {
			return;
		}
		echo '<div class="db-ai-recs">';
		echo '<h2>' . esc_html__( 'Aanbevolen om nu te maken', 'digitale-bazen-ai-module' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Strategie: maak eerst een gestart cluster áf (topical authority), start daarna het cluster met de hoogste totale waarde. Binnen elk: clusterwaarde → zoekvolume → funnel-waarde. Pillar-eerst blijft gelden.', 'digitale-bazen-ai-module' ) . '</p>';
		echo '<ol class="db-ai-recs-list">';
		foreach ( $recs as $r ) {
			$e   = $r['entry'];
			$kw  = (string) ( $e['keyword'] ?? '' );
			$url = add_query_arg(
				[
					'page'          => DB_AI_Admin_Page::MENU_SLUG,
					'plan_keyword'  => $kw,
					'plan_research' => $research_id,
				],
				admin_url( 'admin.php' )
			);
			echo '<li>';
			echo '<div class="db-ai-rec-head"><strong>' . esc_html( $kw ) . '</strong> ';
			echo '<span class="db-ai-role db-ai-role-' . esc_attr( (string) ( $e['role'] ?? '' ) ) . '">' . esc_html( 'pillar' === ( $e['role'] ?? '' ) ? __( 'Pillar', 'digitale-bazen-ai-module' ) : __( 'Supporting', 'digitale-bazen-ai-module' ) ) . '</span>';
			echo '<a class="button button-small button-primary db-ai-rec-gen" href="' . esc_url( $url ) . '">' . esc_html__( 'Genereer', 'digitale-bazen-ai-module' ) . '</a></div>';
			echo '<div class="db-ai-rec-reason">' . esc_html( $r['reason'] ) . '</div>';
			echo '</li>';
		}
		echo '</ol></div>';
	}

	/** Export/import-knoppen (plan meenemen naar een andere site). */
	private function import_export_controls( bool $has_plan ): string {
		$out = '<span class="db-ai-plan-io">';
		if ( $has_plan ) {
			$export = wp_nonce_url( admin_url( 'admin-post.php?action=db_ai_export_plan' ), 'db_ai_export_plan' );
			$out   .= '<a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Exporteer plan', 'digitale-bazen-ai-module' ) . '</a> ';
		}
		$out .= '<input type="file" id="db-ai-import-file" accept=".json,application/json" hidden>';
		$out .= '<button type="button" class="button" id="db-ai-import-plan">' . esc_html__( 'Importeer plan…', 'digitale-bazen-ai-module' ) . '</button>';
		$out .= ' <span id="db-ai-import-status" class="db-ai-status" role="status" aria-live="polite"></span>';
		$out .= '</span>';
		return $out;
	}

	/** Splitst het plan in blog-clusters (pillar + supporting) en niet-blog, en rendert beide. */
	private function render_plan( array $plan, int $research_id, array $volumes = [] ): void {
		$clusters = [];
		$nonblog  = [];

		foreach ( $plan as $entry ) {
			$role = (string) ( $entry['role'] ?? '—' );
			if ( 'pillar' === $role || 'supporting' === $role ) {
				$cluster = (string) ( $entry['cluster'] ?? '' );
				$cluster = '' !== $cluster ? $cluster : __( 'overig', 'digitale-bazen-ai-module' );
				if ( ! isset( $clusters[ $cluster ] ) ) {
					$clusters[ $cluster ] = [ 'pillar' => null, 'supporting' => [] ];
				}
				if ( 'pillar' === $role && null === $clusters[ $cluster ]['pillar'] ) {
					$clusters[ $cluster ]['pillar'] = $entry;
				} else {
					$clusters[ $cluster ]['supporting'][] = $entry;
				}
			} else {
				$nonblog[] = $entry;
			}
		}

		echo '<h2>' . esc_html__( 'Blog-clusters', 'digitale-bazen-ai-module' ) . '</h2>';
		if ( empty( $clusters ) ) {
			echo '<p><em>' . esc_html__( 'Geen informatieve clusters in dit onderzoek.', 'digitale-bazen-ai-module' ) . '</em></p>';
		}

		foreach ( $clusters as $name => $group ) {
			$pillar        = $group['pillar'];
			$pillar_open   = ! $pillar || 'open' === ( $pillar['status'] ?? 'open' );
			echo '<div class="db-ai-cluster">';
			echo '<h3>' . esc_html( $name ) . '</h3>';
			echo '<table class="widefat striped db-ai-plan-table db-ai-plan-table--blog"><thead><tr>';
			echo '<th>' . esc_html__( 'Zoekwoord', 'digitale-bazen-ai-module' ) . '</th>';
			echo '<th>' . esc_html__( 'Volume', 'digitale-bazen-ai-module' ) . '</th>';
			echo '<th>' . esc_html__( 'Rol', 'digitale-bazen-ai-module' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'digitale-bazen-ai-module' ) . '</th>';
			echo '<th class="db-ai-col-action"></th></tr></thead><tbody>';

			if ( $pillar ) {
				$this->render_row( $pillar, $research_id, false, $volumes );
			}
			foreach ( $group['supporting'] as $sup ) {
				// Pillar-eerst: supporting kan pas als de pillar gegenereerd is.
				$this->render_row( $sup, $research_id, $pillar_open, $volumes );
			}
			echo '</tbody></table>';
			echo '</div>';
		}

		if ( ! empty( $nonblog ) ) {
			echo '<h2>' . esc_html__( 'Vaste pagina\'s (geen blog)', 'digitale-bazen-ai-module' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Deze zoekwoorden horen bij een vaste dienst- of lokale pagina, niet bij een blog. Ze dienen als interne-link-doel.', 'digitale-bazen-ai-module' ) . '</p>';
			echo '<table class="widefat striped db-ai-plan-table db-ai-plan-table--pages"><thead><tr>';
			echo '<th>' . esc_html__( 'Zoekwoord', 'digitale-bazen-ai-module' ) . '</th>';
			echo '<th>' . esc_html__( 'Volume', 'digitale-bazen-ai-module' ) . '</th>';
			echo '<th>' . esc_html__( 'Intentie', 'digitale-bazen-ai-module' ) . '</th>';
			echo '<th>' . esc_html__( 'Voorgestelde pagina', 'digitale-bazen-ai-module' ) . '</th></tr></thead><tbody>';
			foreach ( $nonblog as $entry ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $entry['keyword'] ?? '' ) );
				echo $this->companion_cell( $entry, $research_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</td>';
				echo '<td>' . (int) ( $entry['volume'] ?? 0 ) . '</td>';
				echo '<td>' . esc_html( ucfirst( (string) ( $entry['intent'] ?? '' ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry['link_target_hint'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/** Eén blog-rij (pillar of supporting). $blocked = supporting wacht op pillar. */
	private function render_row( array $entry, int $research_id, bool $blocked, array $volumes = [] ): void {
		$keyword = (string) ( $entry['keyword'] ?? '' );
		$role    = (string) ( $entry['role'] ?? '' );
		$status  = (string) ( $entry['status'] ?? 'open' );
		$post_id = (int) ( $entry['post_id'] ?? 0 );

		echo '<tr>';
		echo '<td><strong>' . esc_html( $keyword ) . '</strong>';
		if ( 'pillar' === $role && ! empty( $entry['bundled_keywords'] ) ) {
			$bundled = array_values( array_filter( array_map( 'strval', (array) $entry['bundled_keywords'] ) ) );
			if ( ! empty( $bundled ) ) {
				$pillar_done = in_array( $status, [ 'gegenereerd', 'gepubliceerd' ], true );
				echo '<details class="db-ai-bundled">';
				printf(
					'<summary>%s</summary>',
					esc_html( sprintf(
						/* translators: %d = aantal gebundelde zoektermen */
						_n( '%d gebundelde zoekterm', '%d gebundelde zoektermen', count( $bundled ), 'digitale-bazen-ai-module' ),
						count( $bundled )
					) )
				);
				echo '<p class="db-ai-bundled-note">' . esc_html__( 'Deze synoniemen worden al door de pillar gedekt. Wil je er tóch een los, verwant artikel van maken? Dan wordt het een supporting-artikel dat naar de pillar linkt en overlap vermijdt.', 'digitale-bazen-ai-module' ) . '</p>';
				echo '<ul>';
				foreach ( $bundled as $bk ) {
					$vol = $volumes[ $this->vk( $bk ) ] ?? null;
					echo '<li>' . esc_html( $bk );
					if ( null !== $vol ) {
						echo ' <span class="db-ai-bundled-vol">' . (int) $vol . '</span>';
					}
					echo ' ' . $this->bundled_action( $bk, $research_id, $pillar_done ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '</li>';
				}
				echo '</ul></details>';
			}
		}

		// Funnel-hoek: standaard INGEKLAPT (klik "Hoek" om te zien/bewerken) zodat
		// de tabel rustig blijft. Inline bewerkbaar vóór genereren (§0O/8c).
		$angle  = (string) ( $entry['angle'] ?? '' );
		$funnel = isset( $entry['funnel_target'] ) ? (string) $entry['funnel_target'] : '';
		echo '<details class="db-ai-angle">';
		echo '<summary>' . esc_html__( 'Hoek', 'digitale-bazen-ai-module' );
		if ( '' !== $funnel ) {
			echo ' <span class="db-ai-funnel">→ ' . esc_html( $funnel ) . '</span>';
		}
		echo '</summary>';
		printf(
			'<input type="text" class="db-ai-angle-input" data-keyword="%s" value="%s" placeholder="%s">',
			esc_attr( $keyword ),
			esc_attr( $angle ),
			esc_attr__( 'Inkaderende invalshoek (leeg = pure how-to)…', 'digitale-bazen-ai-module' )
		);
		echo '</details>';

		echo '</td>';
		echo '<td>' . (int) ( $entry['volume'] ?? 0 ) . '</td>';
		echo '<td><span class="db-ai-role db-ai-role-' . esc_attr( $role ) . '">' . esc_html( 'pillar' === $role ? __( 'Pillar', 'digitale-bazen-ai-module' ) : __( 'Supporting', 'digitale-bazen-ai-module' ) ) . '</span></td>';
		echo '<td>' . $this->status_badge( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . $this->action_cell( $keyword, $research_id, $status, $post_id, $blocked ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</tr>';
	}

	/**
	 * Ingeklapte blog-companion onder een vaste-pagina-keyword: als de planner een
	 * eerlijke informatieve invalshoek vond, kun je daar een blog van maken die naar
	 * de dienst funnelt. Het keyword blijft een vaste pagina (link-doel). Leeg als er
	 * geen invalshoek is.
	 */
	private function companion_cell( array $entry, int $research_id ): string {
		$angle = trim( (string) ( $entry['angle'] ?? '' ) );
		if ( '' === $angle ) {
			return '';
		}
		$keyword = (string) ( $entry['keyword'] ?? '' );
		$funnel  = isset( $entry['funnel_target'] ) ? (string) $entry['funnel_target'] : '';
		$status  = (string) ( $entry['status'] ?? 'open' );
		$post_id = (int) ( $entry['post_id'] ?? 0 );

		$out  = '<details class="db-ai-angle db-ai-companion">';
		$out .= '<summary>' . esc_html__( '+ blog-invalshoek', 'digitale-bazen-ai-module' );
		if ( '' !== $funnel ) {
			$out .= ' <span class="db-ai-funnel">→ ' . esc_html( $funnel ) . '</span>';
		}
		$out .= '</summary>';
		$out .= '<p class="db-ai-bundled-note">' . esc_html__( 'Koop-/lokale term (vaste pagina). Het onderwerp leent zich óók voor een informatieve blog die naar de dienst funnelt.', 'digitale-bazen-ai-module' ) . '</p>';
		$out .= sprintf(
			'<input type="text" class="db-ai-angle-input" data-keyword="%s" value="%s" placeholder="%s">',
			esc_attr( $keyword ),
			esc_attr( $angle ),
			esc_attr__( 'Informatieve blog-invalshoek…', 'digitale-bazen-ai-module' )
		);

		if ( in_array( $status, [ 'gegenereerd', 'gepubliceerd' ], true ) && $post_id > 0 ) {
			$edit  = get_edit_post_link( $post_id, 'raw' );
			$out  .= '<div class="db-ai-companion-action">' . $this->status_badge( $status );
			if ( $edit ) {
				$out .= ' <a class="button button-small" href="' . esc_url( $edit ) . '">' . esc_html__( 'Bekijk draft', 'digitale-bazen-ai-module' ) . '</a>';
			}
			$out .= '</div>';
		} else {
			$url  = add_query_arg(
				[
					'page'          => DB_AI_Admin_Page::MENU_SLUG,
					'plan_keyword'  => $keyword,
					'plan_research' => $research_id,
				],
				admin_url( 'admin.php' )
			);
			$out .= '<div class="db-ai-companion-action"><a class="button button-small button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Genereer blog', 'digitale-bazen-ai-module' ) . '</a></div>';
		}
		$out .= '</details>';
		return $out;
	}

	/** Actie achter een gebundelde term: 'm los als verwant supporting-artikel genereren. */
	private function bundled_action( string $keyword, int $research_id, bool $pillar_done ): string {
		if ( ! $pillar_done ) {
			return '<span class="db-ai-bundled-gen disabled" aria-disabled="true" title="' . esc_attr__( 'Genereer eerst de pillar.', 'digitale-bazen-ai-module' ) . '">' . esc_html__( '+ verwant artikel', 'digitale-bazen-ai-module' ) . '</span>';
		}
		$url = add_query_arg(
			[
				'page'          => DB_AI_Admin_Page::MENU_SLUG,
				'plan_keyword'  => $keyword,
				'plan_research' => $research_id,
			],
			admin_url( 'admin.php' )
		);
		return '<a class="db-ai-bundled-gen" href="' . esc_url( $url ) . '">' . esc_html__( '+ verwant artikel', 'digitale-bazen-ai-module' ) . '</a>';
	}

	/** Map zoekterm → maandvolume uit de onderzoek-rijen (voor de gebundelde lijst). */
	private function build_volume_map( int $research_id ): array {
		$research = DB_AI_Keyword_Research::get_with_rows( $research_id );
		if ( is_wp_error( $research ) ) {
			return [];
		}
		$map = [];
		foreach ( (array) $research['rows'] as $row ) {
			$kw = isset( $row['zoekwoord'] ) ? trim( (string) $row['zoekwoord'] ) : '';
			if ( '' !== $kw ) {
				$map[ $this->vk( $kw ) ] = (int) ( $row['volume'] ?? 0 );
			}
		}
		return $map;
	}

	/** Genormaliseerde sleutel (case-/spatie-ongevoelig) voor volume-lookup. */
	private function vk( string $s ): string {
		$s = trim( $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	private function status_badge( string $status ): string {
		$labels = [
			'open'         => __( 'Open', 'digitale-bazen-ai-module' ),
			'gegenereerd'  => __( 'Gegenereerd', 'digitale-bazen-ai-module' ),
			'gepubliceerd' => __( 'Gepubliceerd', 'digitale-bazen-ai-module' ),
		];
		$label = $labels[ $status ] ?? $status;
		return '<span class="db-ai-status-badge db-ai-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	private function action_cell( string $keyword, int $research_id, string $status, int $post_id, bool $blocked ): string {
		// Al gegenereerd/gepubliceerd → link naar de draft.
		if ( in_array( $status, [ 'gegenereerd', 'gepubliceerd' ], true ) && $post_id > 0 ) {
			$edit = get_edit_post_link( $post_id, 'raw' );
			if ( $edit ) {
				return '<a class="button button-small" href="' . esc_url( $edit ) . '">' . esc_html__( 'Bekijk draft', 'digitale-bazen-ai-module' ) . '</a>';
			}
		}

		// Pillar-eerst: supporting wacht tot de pillar gegenereerd is.
		if ( $blocked ) {
			return '<span class="button button-small disabled" aria-disabled="true" title="' . esc_attr__( 'Genereer eerst de pillar van dit cluster.', 'digitale-bazen-ai-module' ) . '">' . esc_html__( 'Genereer', 'digitale-bazen-ai-module' ) . '</span>';
		}

		// 8c: knop bestaat en leidt naar Creatie met het keyword. De plan-context
		// (cluster/rol/pillar/avoid-overlap) wordt in §14 Stap 8d meegegeven.
		$url = add_query_arg(
			[
				'page'          => DB_AI_Admin_Page::MENU_SLUG,
				'plan_keyword'  => $keyword, // add_query_arg encodeert zelf — niet dubbel encoderen.
				'plan_research' => $research_id,
			],
			admin_url( 'admin.php' )
		);
		return '<a class="button button-small button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Genereer', 'digitale-bazen-ai-module' ) . '</a>';
	}

	// ─── AJAX: analyse starten ───────────────────────────────────────────────

	public function analyze_plan(): void {
		if ( ! check_ajax_referer( DB_AI_Ajax::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$current = DB_AI_Keyword_Research::get_current();
		if ( ! $current ) {
			wp_send_json_error( [ 'message' => __( 'Geen zoekwoordenonderzoek om te analyseren.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		if ( '' === trim( DB_AI_Settings::get_api_key( 'anthropic' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Anthropic API-sleutel ontbreekt. Vul hem in bij Generator → Instellingen → API-keys.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		// Plan-analyse telt niet tegen de blog-daglimiet → enforce_rate_limit = false.
		$job_key = DB_AI_Job_Queue::dispatch(
			self::JOB_TYPE,
			[ 'research_id' => (int) $current['id'] ],
			get_current_user_id(),
			false
		);
		if ( is_wp_error( $job_key ) ) {
			wp_send_json_error( [ 'message' => $job_key->get_error_message() ], 400 );
		}

		wp_send_json_success( [ 'job_key' => $job_key, 'status' => 'queued' ] );
	}

	/** Inline bewerkte funnel-hoek opslaan (§0O/8c). */
	public function update_angle(): void {
		if ( ! check_ajax_referer( DB_AI_Ajax::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$angle   = isset( $_POST['angle'] ) ? sanitize_text_field( wp_unslash( $_POST['angle'] ) ) : '';
		if ( '' === $keyword ) {
			wp_send_json_error( [ 'message' => __( 'Geen zoekwoord opgegeven.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$current = DB_AI_Keyword_Research::get_current();
		if ( ! $current ) {
			wp_send_json_error( [ 'message' => __( 'Geen onderzoek.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		DB_AI_Keyword_Research::update_plan_angle( (int) $current['id'], $keyword, $angle );
		wp_send_json_success();
	}

	// ─── Export / import (plan meenemen naar een andere site, geen tokens) ─────

	/** Download het huidige onderzoek + plan als JSON-bestand. */
	public function export_plan(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toegang.', 'digitale-bazen-ai-module' ) );
		}
		check_admin_referer( 'db_ai_export_plan' );

		$current = DB_AI_Keyword_Research::get_current();
		if ( ! $current ) {
			wp_die( esc_html__( 'Geen onderzoek om te exporteren.', 'digitale-bazen-ai-module' ) );
		}
		$id       = (int) $current['id'];
		$research = DB_AI_Keyword_Research::get_with_rows( $id );
		$rows     = is_wp_error( $research ) ? [] : (array) $research['rows'];

		// Plan zonder site-specifieke generatie-status (post_id/status reizen niet mee).
		$plan = [];
		foreach ( DB_AI_Keyword_Research::get_plan( $id ) as $entry ) {
			unset( $entry['status'], $entry['post_id'] );
			$plan[] = $entry;
		}

		$payload = [
			'_format'     => 'digitale-bazen-ai-plan',
			'_version'    => 1,
			'exported_at' => gmdate( 'c' ),
			'name'        => (string) $current['name'],
			'rows'        => $rows,
			'plan'        => $plan,
		];

		$slug = sanitize_title( (string) $current['name'] );
		$file = 'contentplan-' . ( '' !== $slug ? $slug : 'export' ) . '-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/** Importeer een geëxporteerd plan-bestand: herstelt het onderzoek + de classificatie. */
	public function import_plan(): void {
		if ( ! check_ajax_referer( DB_AI_Ajax::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce ongeldig. Herlaad de pagina.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'digitale-bazen-ai-module' ) ], 403 );
		}
		if ( empty( $_FILES['plan']['tmp_name'] ) || ! is_uploaded_file( $_FILES['plan']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen bestand ontvangen.', 'digitale-bazen-ai-module' ) ], 400 );
		}
		$size = (int) ( $_FILES['plan']['size'] ?? 0 );
		if ( $size <= 0 || $size > 4 * MB_IN_BYTES ) {
			wp_send_json_error( [ 'message' => __( 'Bestand ontbreekt of is te groot (max 4 MB).', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$raw  = file_get_contents( $_FILES['plan']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || 'digitale-bazen-ai-plan' !== ( $data['_format'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => __( 'Ongeldig plan-bestand (verkeerd formaat).', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$name = sanitize_text_field( (string) ( $data['name'] ?? __( 'Geïmporteerd onderzoek', 'digitale-bazen-ai-module' ) ) );
		$rows = $this->sanitize_imported_rows( is_array( $data['rows'] ?? null ) ? $data['rows'] : [] );
		if ( empty( $rows ) ) {
			wp_send_json_error( [ 'message' => __( 'Het bestand bevat geen zoekwoorden.', 'digitale-bazen-ai-module' ) ], 400 );
		}

		$id = DB_AI_Keyword_Research::save( $name, $rows );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( [ 'message' => $id->get_error_message() ], 400 );
		}

		$plan = is_array( $data['plan'] ?? null ) ? $this->sanitize_imported_plan( $data['plan'] ) : [];
		if ( ! empty( $plan ) ) {
			DB_AI_Keyword_Research::save_plan( (int) $id, $plan );
		}

		wp_send_json_success( [ 'name' => $name, 'count' => count( $rows ) ] );
	}

	/** Saneer onderzoek-rijen uit een extern bestand. */
	private function sanitize_imported_rows( array $rows ): array {
		$out = [];
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$kw = sanitize_text_field( (string) ( $r['zoekwoord'] ?? '' ) );
			if ( '' === $kw ) {
				continue;
			}
			$out[] = [
				'zoekwoord'    => $kw,
				'volume'       => (int) ( $r['volume'] ?? 0 ),
				'pagina'       => sanitize_text_field( (string) ( $r['pagina'] ?? '' ) ),
				'onderwerp'    => sanitize_text_field( (string) ( $r['onderwerp'] ?? '' ) ),
				'concurrentie' => sanitize_text_field( (string) ( $r['concurrentie'] ?? '' ) ),
			];
		}
		return $out;
	}

	/** Saneer plan-entries uit een extern bestand (whitelist op intent/role). */
	private function sanitize_imported_plan( array $plan ): array {
		$out = [];
		foreach ( $plan as $e ) {
			if ( ! is_array( $e ) || empty( $e['keyword'] ) ) {
				continue;
			}
			$role   = (string) ( $e['role'] ?? '' );
			$intent = (string) ( $e['intent'] ?? '' );
			$ref    = $e['pillar_ref'] ?? null;
			$ft     = $e['funnel_target'] ?? null;
			$out[]  = [
				'keyword'          => sanitize_text_field( (string) $e['keyword'] ),
				'volume'           => (int) ( $e['volume'] ?? 0 ),
				'intent'           => in_array( $intent, [ 'informatief', 'commercieel', 'lokaal' ], true ) ? $intent : 'informatief',
				'cluster'          => sanitize_text_field( (string) ( $e['cluster'] ?? '' ) ),
				'role'             => in_array( $role, [ 'pillar', 'supporting', '—' ], true ) ? $role : '—',
				'pillar_ref'       => is_string( $ref ) && '' !== trim( $ref ) ? sanitize_text_field( $ref ) : null,
				'bundled_keywords' => array_values( array_filter( array_map(
					static function ( $b ) {
						return is_string( $b ) ? sanitize_text_field( $b ) : '';
					},
					(array) ( $e['bundled_keywords'] ?? [] )
				) ) ),
				'angle'            => sanitize_text_field( (string) ( $e['angle'] ?? '' ) ),
				'funnel_target'    => is_string( $ft ) && '' !== trim( $ft ) ? sanitize_text_field( $ft ) : null,
				'bridge'           => sanitize_text_field( (string) ( $e['bridge'] ?? '' ) ),
				'link_target_hint' => sanitize_text_field( (string) ( $e['link_target_hint'] ?? '' ) ),
				'reason'           => sanitize_text_field( (string) ( $e['reason'] ?? '' ) ),
			];
		}
		return $out;
	}

	// ─── Worker-handler ──────────────────────────────────────────────────────

	/**
	 * Draait de planner async en slaat het plan op. Buiten de browser-request.
	 *
	 * @param string $job_key
	 * @param array  $payload  { research_id }
	 * @param int    $user_id
	 */
	public static function run_plan_job( string $job_key, array $payload, int $user_id ): void {
		$research_id = (int) ( $payload['research_id'] ?? 0 );

		DB_AI_Job_Queue::report_progress( $job_key, 10, __( 'Onderzoek laden', 'digitale-bazen-ai-module' ) );
		if ( $research_id <= 0 ) {
			DB_AI_Job_Queue::mark_failed( $job_key, 'db_ai_plan_no_research', __( 'Geen onderzoek opgegeven.', 'digitale-bazen-ai-module' ) );
			return;
		}

		$research = DB_AI_Keyword_Research::get_with_rows( $research_id );
		if ( is_wp_error( $research ) ) {
			DB_AI_Job_Queue::mark_failed( $job_key, (string) $research->get_error_code(), $research->get_error_message() );
			return;
		}

		DB_AI_Job_Queue::report_progress( $job_key, 30, __( 'Zoekwoorden classificeren en clusteren', 'digitale-bazen-ai-module' ) );

		// Grote lijsten worden in batches verwerkt; rapporteer per batch zodat de
		// progress-bar beweegt tussen 30% en 85%.
		$progress = static function ( $done, $total ) use ( $job_key ) {
			$total = max( 1, (int) $total );
			$pct   = 30 + (int) round( ( (int) $done / $total ) * 55 );
			DB_AI_Job_Queue::report_progress(
				$job_key,
				$pct,
				sprintf(
					/* translators: 1 = afgeronde batch, 2 = totaal batches */
					__( 'Zoekwoorden classificeren (batch %1$d/%2$d)', 'digitale-bazen-ai-module' ),
					(int) $done,
					$total
				)
			);
		};

		$plan = ( new DB_AI_Planner() )->build_plan( (array) $research['rows'], $progress );
		if ( is_wp_error( $plan ) ) {
			DB_AI_Job_Queue::mark_failed( $job_key, (string) $plan->get_error_code(), $plan->get_error_message() );
			return;
		}

		DB_AI_Job_Queue::report_progress( $job_key, 90, __( 'Plan opslaan', 'digitale-bazen-ai-module' ) );
		DB_AI_Keyword_Research::save_plan( $research_id, $plan );

		DB_AI_Job_Queue::mark_done( $job_key, [ 'research_id' => $research_id, 'count' => count( $plan ) ] );
	}

	// ─── Plan → Creatie context (§14 Stap 8d) ──────────────────────────────

	/**
	 * Leid de plan-context voor één keyword server-side af (niet vertrouwen op de
	 * client). Geeft de cluster-rol, de pillar-post (voor een forced internal link)
	 * en de titels van al gegenereerde cluster-artikelen (avoid-overlap) terug.
	 *
	 * @return array{cluster:string,cluster_role:string,angle:string,funnel_target:string,bridge:string,pillar_post_id:int,pillar_title:string,avoid_titles:string[]}
	 */
	public static function get_generation_context( int $research_id, string $keyword ): array {
		$empty = [ 'cluster' => '', 'cluster_role' => '', 'angle' => '', 'funnel_target' => '', 'bridge' => '', 'pillar_post_id' => 0, 'pillar_title' => '', 'avoid_titles' => [] ];
		if ( $research_id <= 0 ) {
			return $empty;
		}
		$plan = DB_AI_Keyword_Research::get_plan( $research_id );
		if ( empty( $plan ) ) {
			return $empty;
		}

		$norm = static function ( $s ) {
			$s = trim( (string) $s );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		};
		$target = $norm( $keyword );

		$self = null;
		foreach ( $plan as $entry ) {
			if ( $norm( $entry['keyword'] ?? '' ) === $target ) {
				$self = $entry;
				break;
			}
		}
		// Geen eigen rij? → mogelijk een gebundeld synoniem dat als verwant
		// supporting-artikel onder zijn pillar gegenereerd wordt.
		if ( null === $self ) {
			foreach ( $plan as $entry ) {
				if ( 'pillar' !== (string) ( $entry['role'] ?? '' ) ) {
					continue;
				}
				foreach ( (array) ( $entry['bundled_keywords'] ?? [] ) as $bk ) {
					if ( $norm( $bk ) === $target ) {
						$self = [
							'keyword' => $keyword,
							'cluster' => (string) ( $entry['cluster'] ?? '' ),
							'role'    => 'supporting',
						];
						break 2;
					}
				}
			}
		}
		if ( null === $self ) {
			return $empty;
		}

		$cluster = (string) ( $self['cluster'] ?? '' );
		$role    = (string) ( $self['role'] ?? '' );
		$angle   = (string) ( $self['angle'] ?? '' );
		$bridge  = (string) ( $self['bridge'] ?? '' );
		$ft      = $self['funnel_target'] ?? null;
		$funnel_target = ( is_string( $ft ) && '' !== trim( $ft ) ) ? trim( $ft ) : '';

		$pillar_post_id = 0;
		$pillar_title   = '';
		$avoid_titles   = [];
		if ( '' !== $cluster && in_array( $role, [ 'pillar', 'supporting' ], true ) ) {
			foreach ( $plan as $entry ) {
				if ( (string) ( $entry['cluster'] ?? '' ) !== $cluster ) {
					continue;
				}
				$is_self  = ( $norm( $entry['keyword'] ?? '' ) === $target );
				$done     = in_array( (string) ( $entry['status'] ?? '' ), [ 'gegenereerd', 'gepubliceerd' ], true );
				$post_id  = (int) ( $entry['post_id'] ?? 0 );

				if ( 'supporting' === $role && 'pillar' === (string) ( $entry['role'] ?? '' ) && $done && $post_id > 0 ) {
					$pillar_post_id = $post_id;
					$pillar_title   = (string) get_the_title( $post_id );
				}
				if ( ! $is_self && $done && $post_id > 0 ) {
					$title = get_the_title( $post_id );
					if ( '' !== $title ) {
						$avoid_titles[] = $title;
					}
				}
			}
		}

		return [
			'cluster'        => $cluster,
			'cluster_role'   => $role,
			'angle'          => $angle,
			'funnel_target'  => $funnel_target,
			'bridge'         => $bridge,
			'pillar_post_id' => $pillar_post_id,
			'pillar_title'   => $pillar_title,
			'avoid_titles'   => $avoid_titles,
		];
	}

	/**
	 * Zet de plan-status op `gepubliceerd` zodra een vanuit Plan gegenereerde post
	 * gepubliceerd wordt (§14 Stap 8d). De koppeling staat als post-meta op de post.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! ( $post instanceof WP_Post ) ) {
			return;
		}
		$research = (int) get_post_meta( $post->ID, '_db_ai_plan_research', true );
		$keyword  = (string) get_post_meta( $post->ID, '_db_ai_plan_keyword', true );
		if ( $research > 0 && '' !== $keyword ) {
			DB_AI_Keyword_Research::update_plan_status( $research, $keyword, 'gepubliceerd', $post->ID );
		}
	}
}
