<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

final class Strings {
	private static $rows = array();

	public static function register( $key, $text, $domain = 'default', $source_language = '' ) {
		global $wpdb;
		$table = Database::table( 'strings' );
		$domain = sanitize_key( $domain );
		$key = sanitize_key( $key );
		// The atomic upsert targets SysOpenLang's custom strings table.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic write followed by explicit cache invalidation.
			"INSERT INTO %i (domain,string_key,source_language,source_text,translations,updated_at) VALUES (%s,%s,%s,%s,%s,%s)
			ON DUPLICATE KEY UPDATE source_text = VALUES(source_text), updated_at = VALUES(updated_at)",
			$table, $domain, $key, $source_language ?: Languages::default_code(), $text, '{}', current_time( 'mysql' )
		) );
		self::invalidate( $domain, $key );
		return $text;
	}

	public static function translate( $key, $fallback = '', $domain = 'default', $language = '', $register_missing = true ) {
		global $wpdb;
		$table = Database::table( 'strings' );
		$domain = sanitize_key( $domain );
		$key = sanitize_key( $key );
		$cache_key = $domain . ':' . $key;
		if ( array_key_exists( $cache_key, self::$rows ) ) {
			$row = self::$rows[ $cache_key ];
		} else {
			$row = wp_cache_get( $cache_key, 'openlingua_strings', false, $found );
			if ( ! $found ) {
				// SysOpenLang strings live in a custom table and require a direct prepared lookup.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT source_text, translations FROM %i WHERE domain = %s AND string_key = %s', $table, $domain, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				wp_cache_set( $cache_key, $row ?: false, 'openlingua_strings' );
			}
			self::$rows[ $cache_key ] = $row ?: null;
		}
		if ( ! $row ) { return $register_missing ? self::register( $key, $fallback, $domain ) : $fallback; }
		$translations = json_decode( $row->translations, true );
		$language = $language ?: Languages::current();
		foreach ( Languages::fallback_chain( $language ) as $candidate ) {
			if ( ! empty( $translations[ $candidate ] ) ) { return $translations[ $candidate ]; }
		}
		return $row->source_text;
	}

	public static function translate_plural( $singular_key, $plural_key, $number, $singular, $plural, $domain = 'default', $language = '' ) {
		$key      = 1 === absint( $number ) ? $singular_key : $plural_key;
		$fallback = 1 === absint( $number ) ? $singular : $plural;
		return self::translate( $key, $fallback, $domain, $language );
	}

	public static function invalidate( $domain, $key ) {
		$cache_key = sanitize_key( $domain ) . ':' . sanitize_key( $key );
		unset( self::$rows[ $cache_key ] );
		wp_cache_delete( $cache_key, 'openlingua_strings' );
	}
}
