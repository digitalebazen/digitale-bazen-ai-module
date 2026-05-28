<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Post_Creator {

	public const DEFAULT_POST_TYPE = 'blog';

	private $ai_provider;
	private $acf_mapper;
	private $image_service;
	private $seo_mapper;
	private $logger;

	/** @var callable|null  Optionele progress-callback: fn( int $pct, string $label ): void */
	private $progress_reporter = null;

	public function __construct(
		DB_AI_Provider $ai_provider,
		DB_AI_ACF_Mapper $acf_mapper,
		DB_AI_Image_Service $image_service,
		DB_AI_SEO_Mapper $seo_mapper,
		DB_AI_Logger $logger
	) {
		$this->ai_provider   = $ai_provider;
		$this->acf_mapper    = $acf_mapper;
		$this->image_service = $image_service;
		$this->seo_mapper    = $seo_mapper;
		$this->logger        = $logger;
	}

	/**
	 * Optioneel: koppel een progress-callback (gebruikt door de async job-runner).
	 * Zonder reporter gedraagt de generatie zich exact als voorheen — de
	 * report()-calls zijn no-ops. Houdt Post_Creator ontkoppeld van DB_AI_Job_Queue.
	 */
	public function set_progress_reporter( callable $reporter ): void {
		$this->progress_reporter = $reporter;
	}

	private function report( int $pct, string $label ): void {
		if ( null !== $this->progress_reporter ) {
			call_user_func( $this->progress_reporter, $pct, $label );
		}
	}

	/**
	 * @param array $blog_input  Per-blog input: type_content, funnel_phase,
	 *                           awareness_level, must_include, must_avoid,
	 *                           beat_competition, extra_instructions.
	 *                           Allemaal optioneel — wordt via DB_AI_Blog_Input
	 *                           geformatteerd en aan de user prompt geappended.
	 * @return array|WP_Error  Array met post_id, edit_link, warnings, tokens, model.
	 */
	public function create_from_keyword( string $main_keyword, array $secondary_keywords, int $user_id, array $blog_input = [] ) {
		do_action( 'db_ai_before_generate', $main_keyword, $secondary_keywords, $user_id );

		$this->report( 2, __( 'Zoekwoord-context verzamelen', 'digitale-bazen-ai-module' ) );

		$layout_spec = $this->acf_mapper->get_layout_spec_for_prompt();
		if ( is_wp_error( $layout_spec ) ) {
			return $layout_spec;
		}

		// Bouw eventueel pool van interne links — enabled via Settings.
		// Forced links uit blog_input krijgen voorrang, dan de automatische
		// relevance-pool, samen ge-cap'd op het Settings-max.
		$internal_link_pool   = [];
		$internal_link_max    = 0;
		$internal_link_forced = 0;
		$preprocess_warnings  = [];
		if ( DB_AI_Internal_Links::is_enabled() ) {
			$internal_link_max = DB_AI_Internal_Links::get_max_links();
			$forced_ids        = (array) ( $blog_input['forced_link_ids'] ?? [] );
			$forced            = DB_AI_Internal_Links::get_forced_links( $forced_ids );
			$auto_pool         = DB_AI_Internal_Links::get_link_pool( $main_keyword, $secondary_keywords );
			$merged            = DB_AI_Internal_Links::merge_pools( $forced, $auto_pool, $internal_link_max );
			$internal_link_pool   = $merged['pool'];
			$internal_link_forced = $merged['forced_count'];
		}

		$external_links_max = DB_AI_External_Links::is_enabled() ? DB_AI_External_Links::get_max_suggestions() : 0;

		$ai_context = [
			'layout_spec'          => $layout_spec,
			'output_schema'        => $this->acf_mapper->get_output_schema_example(),
			'blog_input'           => $blog_input,
			'internal_link_pool'   => $internal_link_pool,
			'internal_link_max'    => $internal_link_max,
			'internal_link_forced' => $internal_link_forced,
			'external_links_max'   => $external_links_max,
		];

		$this->report( 12, __( 'Generator schrijft je blog', 'digitale-bazen-ai-module' ) );

		// 1. AI call
		$ai_output = $this->ai_provider->generate_blog( $main_keyword, $secondary_keywords, $ai_context );

		return $this->build_post_from_ai_output( $ai_output, $main_keyword, $secondary_keywords, $user_id, $internal_link_pool, $external_links_max );
	}

	/**
	 * Outline-first fase 2: schrijf de volledige blog volgens een goedgekeurde
	 * outline. Hergebruikt exact dezelfde context-opbouw + post-creatie pipeline
	 * als create_from_keyword; alleen de AI-call verschilt (expand_outline).
	 *
	 * @return array|WP_Error
	 */
	public function create_from_outline( string $main_keyword, array $secondary_keywords, int $user_id, array $approved_outline, array $blog_input = [] ) {
		do_action( 'db_ai_before_generate', $main_keyword, $secondary_keywords, $user_id );

		$this->report( 2, __( 'Structuur voorbereiden', 'digitale-bazen-ai-module' ) );

		$layout_spec = $this->acf_mapper->get_layout_spec_for_prompt();
		if ( is_wp_error( $layout_spec ) ) {
			return $layout_spec;
		}

		$internal_link_pool   = [];
		$internal_link_max    = 0;
		$internal_link_forced = 0;
		if ( DB_AI_Internal_Links::is_enabled() ) {
			$internal_link_max = DB_AI_Internal_Links::get_max_links();
			$forced_ids        = (array) ( $blog_input['forced_link_ids'] ?? [] );
			$forced            = DB_AI_Internal_Links::get_forced_links( $forced_ids );
			$auto_pool         = DB_AI_Internal_Links::get_link_pool( $main_keyword, $secondary_keywords );
			$merged            = DB_AI_Internal_Links::merge_pools( $forced, $auto_pool, $internal_link_max );
			$internal_link_pool   = $merged['pool'];
			$internal_link_forced = $merged['forced_count'];
		}

		$external_links_max = DB_AI_External_Links::is_enabled() ? DB_AI_External_Links::get_max_suggestions() : 0;

		$ai_context = [
			'layout_spec'          => $layout_spec,
			'output_schema'        => $this->acf_mapper->get_output_schema_example(),
			'blog_input'           => $blog_input,
			'internal_link_pool'   => $internal_link_pool,
			'internal_link_max'    => $internal_link_max,
			'internal_link_forced' => $internal_link_forced,
			'external_links_max'   => $external_links_max,
		];

		$this->report( 12, __( 'Generator schrijft je blog', 'digitale-bazen-ai-module' ) );

		$ai_output = $this->ai_provider->expand_outline( $main_keyword, $secondary_keywords, $approved_outline, $ai_context );

		return $this->build_post_from_ai_output( $ai_output, $main_keyword, $secondary_keywords, $user_id, $internal_link_pool, $external_links_max );
	}

	/**
	 * Gedeelde post-creatie pipeline (orphan-cleanup → log). Door zowel
	 * create_from_keyword als create_from_outline gebruikt. Identieke logica als
	 * voorheen — alleen geëxtraheerd zodat beide flows 'm delen.
	 *
	 * @return array|WP_Error
	 */
	private function build_post_from_ai_output( $ai_output, string $main_keyword, array $secondary_keywords, int $user_id, array $internal_link_pool, int $external_links_max ) {
		if ( is_wp_error( $ai_output ) ) {
			$this->log_failure( 0, $user_id, $main_keyword, 'ai_error', $ai_output->get_error_message() );
			do_action( 'db_ai_generation_failed', $ai_output, $main_keyword, $user_id );
			return $ai_output;
		}

		$preprocess_warnings = [];

		// Cleanup: strip <a>-tags die naar niet-bestaande interne URLs wijzen.
		// AI kan ondanks expliciete instructie alsnog een URL verzinnen — die
		// vervangen we door alleen de anchor-text om 404's te voorkomen.
		if ( ! empty( $internal_link_pool ) ) {
			$stripped = DB_AI_Internal_Links::clean_orphan_links( $ai_output, $internal_link_pool );
			if ( $stripped > 0 ) {
				/* translators: %d = aantal opgeruimde links */
				$preprocess_warnings[] = sprintf(
					__( '%d niet-bestaande interne link(s) verwijderd uit de output.', 'digitale-bazen-ai-module' ),
					$stripped
				);
			}
		}

		$this->report( 50, __( 'Output valideren', 'digitale-bazen-ai-module' ) );

		// 2. Validate
		$validation = $this->acf_mapper->validate_ai_output( $ai_output, $main_keyword );
		if ( ! $validation['valid'] ) {
			$err = new WP_Error(
				'db_ai_validation_failed',
				__( 'AI output is niet valide volgens schema.', 'digitale-bazen-ai-module' ),
				[ 'validation_errors' => $validation['errors'] ]
			);
			$this->log_failure( 0, $user_id, $main_keyword, 'validation_error', implode( '; ', $validation['errors'] ) );
			do_action( 'db_ai_generation_failed', $err, $main_keyword, $user_id );
			return $err;
		}
		$warnings = array_merge( $preprocess_warnings, $validation['warnings'] );

		// 3. Insert draft post
		$post_type = (string) apply_filters( 'db_ai_post_type', self::DEFAULT_POST_TYPE );
		$post_id   = wp_insert_post(
			[
				'post_title'   => sanitize_text_field( (string) ( $ai_output['post']['title'] ?? '' ) ),
				'post_name'    => sanitize_title( (string) ( $ai_output['post']['slug'] ?? '' ) ),
				'post_excerpt' => sanitize_textarea_field( (string) ( $ai_output['post']['excerpt'] ?? '' ) ),
				'post_status'  => 'draft',
				'post_type'    => $post_type,
				'post_author'  => $user_id,
			],
			true
		);
		if ( is_wp_error( $post_id ) ) {
			$this->log_failure( 0, $user_id, $main_keyword, 'insert_error', $post_id->get_error_message() );
			do_action( 'db_ai_generation_failed', $post_id, $main_keyword, $user_id );
			return $post_id;
		}

		$this->report( 60, __( 'Blog aanmaken', 'digitale-bazen-ai-module' ) );

		// 4. Walk blocks, replace image objects with attachment IDs
		$transformed_blocks   = [];
		$first_block_image_id = 0;
		$block_total          = max( 1, count( $ai_output['blocks'] ) );
		foreach ( $ai_output['blocks'] as $i => $block ) {
			// Afbeeldingen ophalen is de traagste stap — laat de bar meebewegen van 60→88%.
			$this->report(
				60 + (int) round( ( $i / $block_total ) * 28 ),
				sprintf(
					/* translators: 1 = huidig block, 2 = totaal */
					__( 'Afbeeldingen ophalen (%1$d/%2$d)', 'digitale-bazen-ai-module' ),
					$i + 1,
					$block_total
				)
			);
			$transformed         = $this->process_block_images( $block, $post_id, $warnings, $i );
			$transformed_blocks[] = $transformed;
			if ( 0 === $first_block_image_id && ! empty( $transformed['afbeelding'] ) && is_int( $transformed['afbeelding'] ) ) {
				$first_block_image_id = (int) $transformed['afbeelding'];
			}
		}

		$this->report( 90, __( 'Coverfoto kiezen', 'digitale-bazen-ai-module' ) );

		// 5. Featured image
		$featured = $this->image_service->find_and_import(
			(string) ( $ai_output['featured_image']['query'] ?? '' ),
			(string) ( $ai_output['featured_image']['alt'] ?? '' ),
			$post_id
		);
		if ( is_wp_error( $featured ) ) {
			$warnings[] = sprintf(
				/* translators: %s = error */
				__( 'Featured image gefaald: %s', 'digitale-bazen-ai-module' ),
				$featured->get_error_message()
			);
			$featured = $first_block_image_id ?: 0;
			if ( $featured ) {
				$warnings[] = __( 'Featured image teruggevallen op eerste block-afbeelding.', 'digitale-bazen-ai-module' );
			} else {
				$warnings[] = __( 'Geen featured image (geen enkele block-afbeelding gelukt).', 'digitale-bazen-ai-module' );
			}
		}
		if ( is_int( $featured ) && $featured > 0 ) {
			set_post_thumbnail( $post_id, $featured );
		}

		$this->report( 95, __( 'Blocks invullen', 'digitale-bazen-ai-module' ) );

		// 6. ACF write
		$this->acf_mapper->write_blocks_to_post( $post_id, $transformed_blocks );

		// 7. SEO mapper
		$this->seo_mapper->apply( $post_id, (array) ( $ai_output['seo'] ?? [] ) );

		$this->report( 98, __( 'Afronden', 'digitale-bazen-ai-module' ) );

		// 8. Meta
		$model  = $this->ai_provider->get_model_identifier();
		$tokens = $this->ai_provider->get_last_token_usage();
		update_post_meta( $post_id, '_db_ai_generated', 1 );
		update_post_meta( $post_id, '_db_ai_generated_at', current_time( 'mysql', true ) );
		update_post_meta( $post_id, '_db_ai_keyword', $main_keyword );
		update_post_meta( $post_id, '_db_ai_secondary_keywords', implode( ',', $secondary_keywords ) );
		update_post_meta( $post_id, '_db_ai_model', $model );
		update_post_meta( $post_id, '_db_ai_tokens_used', $tokens );
		if ( ! empty( $warnings ) ) {
			update_post_meta( $post_id, '_db_ai_warnings', $warnings );
		}

		// Externe link-suggesties — opslaan als post meta, metabox toont ze later.
		if ( $external_links_max > 0 ) {
			$suggestions = DB_AI_External_Links::sanitize_suggestions(
				$ai_output['external_link_suggestions'] ?? [],
				$external_links_max
			);
			if ( ! empty( $suggestions ) ) {
				update_post_meta( $post_id, DB_AI_External_Links::META_KEY, $suggestions );
			}
		}

		// 9. Log
		$this->logger->log( $post_id, $user_id, $main_keyword, $model, $tokens, 'success', '', $warnings );

		// 10. Action hook
		do_action( 'db_ai_after_post_created', $post_id, $ai_output, $user_id );

		// 11. Return summary
		return [
			'post_id'      => $post_id,
			'edit_link'    => get_edit_post_link( $post_id, 'raw' ),
			'preview_link' => get_preview_post_link( $post_id ),
			'warnings'     => $warnings,
			'tokens'       => $tokens,
			'model'        => $model,
		];
	}

	/**
	 * Vervangt ALLE image-objecten ({query, alt}) in het block door attachment IDs,
	 * recursief op elk nestingniveau. Site-agnostisch — detectie op signatuur, niet
	 * op hardcoded field/layout-namen.
	 *
	 * Een image-object is een associative array met:
	 *  - 'query' (string, niet leeg)
	 *  - 'alt' (string, optioneel)
	 *  - geen numerieke keys (om lists te onderscheiden van objecten)
	 *  - maximaal 3 keys (defensief tegen rare AI-output)
	 */
	private function process_block_images( array $block, int $post_id, array &$warnings, int $block_index ): array {
		$layout = $block['acf_fc_layout'] ?? '';
		$this->walk_and_resolve_images( $block, $post_id, $warnings, $block_index, $layout, '' );
		return $block;
	}

	private function walk_and_resolve_images( array &$data, int $post_id, array &$warnings, int $block_index, string $layout, string $path ): void {
		foreach ( $data as $key => &$value ) {
			$current_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( $this->is_image_object( $value ) ) {
				$att_id = $this->image_service->find_and_import(
					(string) $value['query'],
					(string) ( $value['alt'] ?? '' ),
					$post_id
				);
				if ( is_wp_error( $att_id ) ) {
					$warnings[] = sprintf(
						/* translators: 1=block index, 2=layout, 3=path, 4=query, 5=error */
						__( 'Block %1$d (%2$s) afbeelding op "%3$s" — query "%4$s" — gefaald: %5$s', 'digitale-bazen-ai-module' ),
						$block_index,
						$layout,
						$current_path,
						$value['query'],
						$att_id->get_error_message()
					);
					$value = '';
				} else {
					$value = (int) $att_id;
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$this->walk_and_resolve_images( $value, $post_id, $warnings, $block_index, $layout, $current_path );
			}
		}
	}

	/**
	 * Detect of een waarde een AI-image-object is: associatieve array met
	 * 'query' string en optionele 'alt'. Bewust strikt om lists/random arrays uit te sluiten.
	 */
	private function is_image_object( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( ! isset( $value['query'] ) || ! is_string( $value['query'] ) || '' === trim( $value['query'] ) ) {
			return false;
		}
		// Geen numerieke keys (zou een list zijn, geen object)
		foreach ( array_keys( $value ) as $k ) {
			if ( is_int( $k ) ) {
				return false;
			}
		}
		// Defensief: max 3 keys ('query', 'alt' + eventueel een derde)
		if ( count( $value ) > 3 ) {
			return false;
		}
		return true;
	}

	private function log_failure( int $post_id, int $user_id, string $keyword, string $status, string $error ): void {
		$this->logger->log(
			$post_id,
			$user_id,
			$keyword,
			$this->ai_provider->get_model_identifier(),
			$this->ai_provider->get_last_token_usage(),
			$status,
			$error
		);
	}
}
