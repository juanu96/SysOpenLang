<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

/** Stores and reuses exact translations locally. */
final class Translation_Memory {
	private static $pairs = array();

	public static function normalize( $text, $format = 'text' ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		if ( 'html' === $format ) {
			$text = preg_replace( '/>\s+</u', '><', trim( $text ) );
		} else {
			$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
		}
		return is_string( $text ) ? $text : '';
	}

	public static function key( $source, $format = 'text', $context = '' ) {
		$normalized = self::normalize( $source, $format );
		$context = sanitize_key( $context );
		return hash( 'sha256', $context ? $normalized . "\ncontext:" . $context : $normalized );
	}

	public static function find( $source, $source_language, $target_language, $format = 'text', $context = '' ) {
		$index = self::pair_index( $source_language, $target_language );
		$context_key = $format . ':' . self::key( $source, $format, $context );
		if ( $context && isset( $index[ $context_key ] ) ) { return $index[ $context_key ]; }
		$generic_key = $format . ':' . self::key( $source, $format );
		return isset( $index[ $generic_key ] ) ? $index[ $generic_key ] : '';
	}

	public static function remember( $source, $translation, $source_language, $target_language, $format = 'text', $context = '', $origin = 'manual', $approved = true ) {
		global $wpdb;
		$source = self::normalize( $source, $format );
		$translation = trim( (string) $translation );
		$source_language = sanitize_key( $source_language );
		$target_language = sanitize_key( $target_language );
		$format = 'html' === $format ? 'html' : 'text';
		if ( ! self::is_eligible( $source, $translation ) || ! $source_language || ! $target_language || $source_language === $target_language ) { return false; }
		$table = Database::table( 'memory' );
		$context = sanitize_key( $context );
		$origin = in_array( $origin, array( 'manual', 'automatic', 'imported' ), true ) ? $origin : 'manual';
		$hash = self::key( $source, $format, $context );
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_language = %s AND target_language = %s AND source_hash = %s AND format = %s', $table, $source_language, $target_language, $hash, $format ) );
		$data = array( 'source_text' => $source, 'translation' => $translation, 'context' => $context, 'origin' => $origin, 'approved' => $approved ? 1 : 0, 'updated_at' => current_time( 'mysql' ) );
		if ( $existing ) {
			$result = $wpdb->update( $table, $data, array( 'id' => absint( $existing ) ) );
		} else {
			$data += array( 'source_language' => $source_language, 'target_language' => $target_language, 'source_hash' => $hash, 'format' => $format );
			$result = $wpdb->insert( $table, $data );
		}
		unset( self::$pairs[ $source_language . ':' . $target_language ] );
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( md5( $source_language . ':' . $target_language ), 'openlingua_memory' ); }
		if ( $context ) { self::remember( $source, $translation, $source_language, $target_language, $format, '', $origin, $approved ); }
		return false !== $result;
	}

	public static function learn_post( $source_id, $target_id, $origin = 'manual', $approved = true ) {
		$source = get_post( $source_id );
		$target = get_post( $target_id );
		$source_row = Translations::row( 'post', $source_id );
		$target_row = Translations::row( 'post', $target_id );
		if ( ! $source || ! $target || ! $source_row || ! $target_row ) { return; }
		$remember = function ( $original, $translated, $format = 'text', $context = '' ) use ( $source_row, $target_row, $origin, $approved ) {
			self::remember( $original, $translated, $source_row->language, $target_row->language, $format, $context, $origin, $approved );
		};
		$remember( $source->post_title, $target->post_title, 'text', 'post-title' );
		$remember( $source->post_excerpt, $target->post_excerpt, 'html', 'post-excerpt' );
		$is_divi = Divi_Content::is_divi( $source->post_content );
		$is_gutenberg = ! $is_divi && Gutenberg_Content::is_gutenberg( $source->post_content );
		$content_extractor = ! $is_divi && ! $is_gutenberg ? Content_Extractors::for_post( $source ) : null;
		if ( $is_divi ) {
			self::learn_segments( Divi_Content::extract( $source->post_content ), Divi_Content::values( $target->post_content ), $remember, 'kind' );
		} elseif ( $is_gutenberg ) {
			self::learn_segments( Gutenberg_Content::extract( $source->post_content ), Gutenberg_Content::values( $target->post_content ), $remember, 'format' );
		} elseif ( $content_extractor ) {
			self::learn_segments( $content_extractor->extract( $source ), $content_extractor->values( $target ), $remember, 'format' );
		} else {
			$remember( $source->post_content, $target->post_content, 'html', 'post-content' );
		}
		self::learn_segments( ACF_Content::extract( $source_id ), ACF_Content::values( $target_id ), $remember, 'format' );
		foreach ( \SysOpenLang\Modules\Commerce::translation_fields( $source_id, $target_id ) as $field ) { $remember( $field['source'], $field['target'], 'html', $field['id'] ); }
		foreach ( SEO::translation_fields( $source_id, $target_id ) as $group ) {
			foreach ( $group['fields'] as $field ) { $remember( $field['source'], $field['target'], 'text', 'seo-' . $field['key'] ); }
		}
	}

	/** Imports translations created before translation memory was available. */
	public static function import_existing_pair( $source_language, $target_language ) {
		global $wpdb;
		$source_language = sanitize_key( $source_language );
		$target_language = sanitize_key( $target_language );
		$option = 'openlingua_memory_import_' . md5( $source_language . ':' . $target_language );
		if ( get_option( $option ) ) { return; }
		$table = Database::table( 'translations' );
		$pairs = $wpdb->get_results( $wpdb->prepare(
			'SELECT source.element_id AS source_id, target.element_id AS target_id FROM %i AS target INNER JOIN %i AS source ON source.group_uuid = target.group_uuid AND source.element_type = target.element_type WHERE target.element_type = %s AND target.language = %s AND source.language = %s',
			$table,
			$table,
			'post',
			$target_language,
			$source_language
		) );
		foreach ( (array) $pairs as $pair ) { self::learn_post( absint( $pair->source_id ), absint( $pair->target_id ) ); }
		update_option( $option, current_time( 'mysql' ), false );
	}

	private static function learn_segments( array $segments, array $values, $remember, $format_key ) {
		foreach ( $segments as $segment ) {
			if ( ! array_key_exists( $segment['id'], $values ) ) { continue; }
			$is_html = 'html' === ( $segment[ $format_key ] ?? '' ) || 'content' === ( $segment[ $format_key ] ?? '' );
			$remember( $segment['value'], $values[ $segment['id'] ], $is_html ? 'html' : 'text', $segment['label'] ?? $segment['id'] );
		}
	}

	private static function is_eligible( $source, $translation ) {
		if ( '' === $source || '' === $translation || self::normalize( $translation ) === self::normalize( $source ) ) { return false; }
		if ( preg_match( '#^(?:https?://|/|\d+(?:[.,]\d+)?\s*)$#i', $source ) ) { return false; }
		return (bool) preg_match( '/[\p{L}\p{N}]/u', wp_strip_all_tags( $source ) );
	}

	private static function pair_index( $source_language, $target_language ) {
		global $wpdb;
		$source_language = sanitize_key( $source_language );
		$target_language = sanitize_key( $target_language );
		$cache_key = $source_language . ':' . $target_language;
		if ( isset( self::$pairs[ $cache_key ] ) ) { return self::$pairs[ $cache_key ]; }
		$persistent_key = md5( $cache_key );
		if ( function_exists( 'wp_cache_get' ) ) {
			$found = false;
			$cached = wp_cache_get( $persistent_key, 'openlingua_memory', false, $found );
			if ( $found && is_array( $cached ) ) { self::$pairs[ $cache_key ] = $cached; return $cached; }
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_hash, format, translation FROM %i WHERE source_language = %s AND target_language = %s', Database::table( 'memory' ), $source_language, $target_language ) );
		$index = array();
		foreach ( (array) $rows as $row ) { $index[ $row->format . ':' . $row->source_hash ] = $row->translation; }
		self::$pairs[ $cache_key ] = $index;
		if ( function_exists( 'wp_cache_set' ) ) { wp_cache_set( $persistent_key, $index, 'openlingua_memory', HOUR_IN_SECONDS ); }
		return $index;
	}
}
