<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-detectie van ACF field groups die een `flexible_content` veld bevatten.
 * Gebruikt door de Settings page om dropdowns te vullen en door DB_AI_Settings
 * om een sensible default te kiezen als de gebruiker nog niets heeft gekozen.
 *
 * Een site kan meerdere flex field groups hebben — bv. één voor pages en één voor
 * een CPT. De gebruiker kiest in Settings welke de plugin moet gebruiken voor AI
 * blog-generatie.
 */
class DB_AI_ACF_Discovery {

	/**
	 * Alle field groups die minstens één flexible_content veld bevatten.
	 *
	 * Cached per request (statische memoization).
	 *
	 * @return array<int, array{key:string,title:string,flex_fields:array<int,array{name:string,label:string,layouts:array<int,array{name:string,label:string}>}>}>
	 */
	public static function find_flex_field_groups(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = [];

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $cache;
		}

		foreach ( acf_get_field_groups() as $group ) {
			$key = (string) ( $group['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			$fields = acf_get_fields( $group );
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$flex_fields = [];
			foreach ( $fields as $field ) {
				if ( ( $field['type'] ?? '' ) !== 'flexible_content' ) {
					continue;
				}
				$layouts = [];
				foreach ( ( $field['layouts'] ?? [] ) as $layout ) {
					$name = (string) ( $layout['name'] ?? '' );
					if ( '' === $name ) {
						continue;
					}
					$layouts[] = [
						'name'  => $name,
						'label' => (string) ( $layout['label'] ?? $name ),
					];
				}
				if ( empty( $layouts ) ) {
					continue;
				}
				$flex_fields[] = [
					'name'    => (string) ( $field['name'] ?? '' ),
					'label'   => (string) ( $field['label'] ?? $field['name'] ?? '' ),
					'layouts' => $layouts,
				];
			}

			if ( empty( $flex_fields ) ) {
				continue;
			}

			$cache[] = [
				'key'         => $key,
				'title'       => (string) ( $group['title'] ?? $key ),
				'flex_fields' => $flex_fields,
			];
		}

		return $cache;
	}

	/**
	 * Layouts voor een specifieke group/flex-combinatie. Lege array als niets matcht.
	 *
	 * @return array<int, array{name:string,label:string}>
	 */
	public static function get_layouts_for( string $group_key, string $flex_name ): array {
		foreach ( self::find_flex_field_groups() as $group ) {
			if ( $group['key'] !== $group_key ) {
				continue;
			}
			foreach ( $group['flex_fields'] as $flex ) {
				if ( $flex['name'] === $flex_name ) {
					return $flex['layouts'];
				}
			}
		}
		return [];
	}

	/**
	 * Heeft deze site überhaupt een bruikbare flex field group?
	 */
	public static function has_any(): bool {
		return ! empty( self::find_flex_field_groups() );
	}
}
