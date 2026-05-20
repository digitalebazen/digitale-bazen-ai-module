<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_AI_Keyword_Importer {

	public const HEADER_KEYWORD     = 'zoekwoord';
	public const HEADER_VOLUME      = 'maandelijks volume';
	public const HEADER_PAGE        = 'pagina';
	public const HEADER_TOPIC       = 'onderwerp';
	public const HEADER_COMPETITION = 'concurrentie';
	public const HEADER_CPC_LOW     = 'cpc laag';
	public const HEADER_CPC_HIGH    = 'cpc hoog';

	public const UNGROUPED_PAGE  = '(Geen pagina)';
	public const UNGROUPED_TOPIC = '(Geen onderwerp)';

	/**
	 * Parse a CSV file with keyword research data.
	 *
	 * @return array|WP_Error  ['rows' => [...], 'grouped' => [pagina => [onderwerp => [rows]]]]
	 */
	public function parse_csv( string $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'db_ai_csv_unreadable', __( 'Het CSV-bestand kon niet gelezen worden.', 'digitale-bazen-ai-module' ) );
		}

		$contents = file_get_contents( $file_path );
		if ( false === $contents || '' === trim( $contents ) ) {
			return new WP_Error( 'db_ai_csv_empty', __( 'Het CSV-bestand is leeg.', 'digitale-bazen-ai-module' ) );
		}

		// Strip UTF-8 BOM.
		if ( substr( $contents, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$contents = substr( $contents, 3 );
		}

		$lines = preg_split( '/\r\n|\r|\n/', $contents );
		$lines = array_values( array_filter( $lines, static fn( $l ) => '' !== trim( $l ) ) );

		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'db_ai_csv_no_rows', __( 'CSV bevat geen data-regels.', 'digitale-bazen-ai-module' ) );
		}

		// Sniff delimiter from the first non-trivial line.
		$delimiter = ';';
		foreach ( $lines as $candidate ) {
			$bare = str_replace( [ ',', ';', "\t", ' ' ], '', $candidate );
			if ( '' !== $bare ) {
				$delimiter = $this->detect_delimiter( $candidate );
				break;
			}
		}

		// Find the header row by scanning for a "zoekwoord" cell. Some CSV-exports prepend
		// blank rows ("`,,,,,`") before the actual header.
		$header_line_idx = -1;
		$headers         = [];
		$scan_limit      = min( count( $lines ), 10 );
		for ( $i = 0; $i < $scan_limit; $i++ ) {
			$candidate = array_map( fn( $h ) => strtolower( trim( $h ) ), $this->parse_line( $lines[ $i ], $delimiter ) );
			if ( in_array( self::HEADER_KEYWORD, $candidate, true ) ) {
				$header_line_idx = $i;
				$headers         = $candidate;
				break;
			}
		}

		if ( -1 === $header_line_idx ) {
			$first_real_row = '';
			foreach ( $lines as $l ) {
				$bare = str_replace( [ ',', ';', "\t", ' ' ], '', $l );
				if ( '' !== $bare ) {
					$first_real_row = $l;
					break;
				}
			}
			$detected = array_map( fn( $h ) => trim( $h ), $this->parse_line( $first_real_row, $delimiter ) );
			return new WP_Error(
				'db_ai_csv_missing_keyword_column',
				sprintf(
					/* translators: %s = expected column name */
					__( 'Verplichte kolom "%s" ontbreekt in CSV. Beschikbare kolommen: ', 'digitale-bazen-ai-module' ),
					'Zoekwoord'
				) . implode( ', ', array_filter( $detected, static fn( $v ) => '' !== $v ) )
			);
		}

		$keyword_idx = array_search( self::HEADER_KEYWORD, $headers, true );

		$col_map = [
			'zoekwoord'    => $keyword_idx,
			'volume'       => array_search( self::HEADER_VOLUME, $headers, true ),
			'pagina'       => array_search( self::HEADER_PAGE, $headers, true ),
			'onderwerp'    => array_search( self::HEADER_TOPIC, $headers, true ),
			'concurrentie' => array_search( self::HEADER_COMPETITION, $headers, true ),
			'cpc_laag'     => array_search( self::HEADER_CPC_LOW, $headers, true ),
			'cpc_hoog'     => array_search( self::HEADER_CPC_HIGH, $headers, true ),
		];

		$rows    = [];
		$grouped = [];

		for ( $i = $header_line_idx + 1; $i < count( $lines ); $i++ ) {
			$cells   = $this->parse_line( $lines[ $i ], $delimiter );
			$keyword = isset( $cells[ $keyword_idx ] ) ? trim( $cells[ $keyword_idx ] ) : '';
			if ( '' === $keyword ) {
				continue;
			}

			$row = [
				'zoekwoord'    => $keyword,
				'volume'       => $this->read_int( $cells, $col_map['volume'] ),
				'pagina'       => $this->read_text( $cells, $col_map['pagina'] ),
				'onderwerp'    => $this->read_text( $cells, $col_map['onderwerp'] ),
				'concurrentie' => $this->read_text( $cells, $col_map['concurrentie'] ),
				'cpc_laag'     => $this->read_nl_number( $cells, $col_map['cpc_laag'] ),
				'cpc_hoog'     => $this->read_nl_number( $cells, $col_map['cpc_hoog'] ),
			];

			$rows[] = $row;

			$page  = '' !== $row['pagina'] ? $row['pagina'] : self::UNGROUPED_PAGE;
			$topic = '' !== $row['onderwerp'] ? $row['onderwerp'] : self::UNGROUPED_TOPIC;

			if ( ! isset( $grouped[ $page ] ) ) {
				$grouped[ $page ] = [];
			}
			if ( ! isset( $grouped[ $page ][ $topic ] ) ) {
				$grouped[ $page ][ $topic ] = [];
			}
			$grouped[ $page ][ $topic ][] = $row;
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'db_ai_csv_no_keywords', __( 'CSV bevat geen geldige zoekwoorden.', 'digitale-bazen-ai-module' ) );
		}

		return [
			'rows'    => $rows,
			'grouped' => $grouped,
		];
	}

	/**
	 * Find rows with the same 'onderwerp' as the main keyword, return their zoekwoord strings (excluding main).
	 *
	 * @return string[]
	 */
	public function get_secondary_keywords( array $rows, string $main_keyword ): array {
		$main_keyword = trim( $main_keyword );
		if ( '' === $main_keyword ) {
			return [];
		}

		$main_topic = null;
		foreach ( $rows as $row ) {
			if ( 0 === strcasecmp( $row['zoekwoord'], $main_keyword ) ) {
				$main_topic = $row['onderwerp'];
				break;
			}
		}

		if ( null === $main_topic || '' === $main_topic ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( $row['onderwerp'] !== $main_topic ) {
				continue;
			}
			if ( 0 === strcasecmp( $row['zoekwoord'], $main_keyword ) ) {
				continue;
			}
			$out[] = $row['zoekwoord'];
		}
		return $out;
	}

	private function detect_delimiter( string $line ): string {
		$candidates = [ ';' => 0, ',' => 0, "\t" => 0 ];
		foreach ( $candidates as $char => $_ ) {
			$candidates[ $char ] = substr_count( $line, $char );
		}
		arsort( $candidates );
		$top = array_key_first( $candidates );
		return $candidates[ $top ] > 0 ? $top : ';';
	}

	private function parse_line( string $line, string $delimiter ): array {
		$handle = fopen( 'php://memory', 'r+' );
		fwrite( $handle, $line );
		rewind( $handle );
		$cells = fgetcsv( $handle, 0, $delimiter, '"', '\\' );
		fclose( $handle );
		return is_array( $cells ) ? $cells : [];
	}

	private function read_text( array $cells, $idx ): string {
		if ( false === $idx || ! isset( $cells[ $idx ] ) ) {
			return '';
		}
		return trim( $cells[ $idx ] );
	}

	private function read_int( array $cells, $idx ): int {
		$value = $this->read_text( $cells, $idx );
		if ( '' === $value ) {
			return 0;
		}
		// Strip non-digits (NL might write "2.900" or "2,900").
		$value = preg_replace( '/[^0-9]/', '', $value );
		return (int) $value;
	}

	private function read_nl_number( array $cells, $idx ): float {
		$value = $this->read_text( $cells, $idx );
		if ( '' === $value ) {
			return 0.0;
		}
		// NL: '.' = thousand sep, ',' = decimal sep. Strip dots, swap comma to dot.
		$value = str_replace( '.', '', $value );
		$value = str_replace( ',', '.', $value );
		return is_numeric( $value ) ? (float) $value : 0.0;
	}
}
