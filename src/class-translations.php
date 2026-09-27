<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

final class Translations {
	private static $rows = array();
	private static $groups = array();

	public static function assign( $element_type, $element_id, $language, $group_uuid = '', $source_language = '' ) {
		global $wpdb;
		$language = sanitize_key( $language );
		if ( ! Languages::is_valid( $language ) ) {
			return new \WP_Error( 'openlingua_invalid_language', __( 'Invalid language.', 'sysopenlang' ) );
		}
		$group_uuid = $group_uuid ?: wp_generate_uuid4();
		$table      = Database::table( 'translations' );
		$previous   = self::row( $element_type, $element_id );
		$existing   = $previous ? absint( $previous->id ) : 0;
		$data       = array(
			'group_uuid'     => sanitize_text_field( $group_uuid ),
			'element_type'   => sanitize_key( $element_type ),
			'element_id'     => absint( $element_id ),
			'language'       => $language,
			'source_language'=> sanitize_key( $source_language ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => absint( $existing ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write followed by cache invalidation.
		} else {
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writes to SysOpenLang's custom relationship table.
		}
		self::invalidate( $element_type, $element_id, $previous ? $previous->group_uuid : '' );
		self::invalidate( $element_type, $element_id, $group_uuid );
		return $wpdb->last_error ? new \WP_Error( 'openlingua_db_error', $wpdb->last_error ) : $group_uuid;
	}

	public static function row( $element_type, $element_id ) {
		global $wpdb;
		$key = sanitize_key( $element_type ) . ':' . absint( $element_id );
		if ( array_key_exists( $key, self::$rows ) ) { return self::$rows[ $key ]; }
		$cached = wp_cache_get( $key, 'openlingua_rows', false, $found );
		if ( $found ) { self::$rows[ $key ] = $cached ?: null; return self::$rows[ $key ]; }
		$table = Database::table( 'translations' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE element_type = %s AND element_id = %d', $table, $element_type, $element_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached lookup in SysOpenLang's custom table.
		self::$rows[ $key ] = $row;
		wp_cache_set( $key, $row ?: false, 'openlingua_rows' );
		return $row;
	}

	public static function group( $element_type, $element_id ) {
		global $wpdb;
		$row = self::row( $element_type, $element_id );
		if ( ! $row ) {
			return array();
		}
		$cache_key = sanitize_key( $element_type ) . ':' . $row->group_uuid;
		if ( isset( self::$groups[ $cache_key ] ) ) { return self::$groups[ $cache_key ]; }
		$cached = wp_cache_get( $cache_key, 'openlingua_groups', false, $found );
		if ( $found ) { self::$groups[ $cache_key ] = is_array( $cached ) ? $cached : array(); return self::$groups[ $cache_key ]; }
		$table = Database::table( 'translations' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT language, element_id FROM %i WHERE element_type = %s AND group_uuid = %s', $table, $element_type, $row->group_uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached lookup in SysOpenLang's custom table.
		self::$groups[ $cache_key ] = wp_list_pluck( $rows, 'element_id', 'language' );
		wp_cache_set( $cache_key, self::$groups[ $cache_key ], 'openlingua_groups' );
		return self::$groups[ $cache_key ];
	}

	public static function translated_id( $element_type, $element_id, $language ) {
		$group = self::group( $element_type, $element_id );
		return isset( $group[ $language ] ) ? absint( $group[ $language ] ) : 0;
	}

	public static function translated_id_with_fallback( $element_type, $element_id, $language ) {
		foreach ( Languages::fallback_chain( $language ) as $candidate ) {
			$translated_id = self::translated_id( $element_type, $element_id, $candidate );
			if ( $translated_id ) { return $translated_id; }
		}
		return 0;
	}

	public static function delete( $element_type, $element_id ) {
		global $wpdb;
		$row = self::row( $element_type, $element_id );
		$wpdb->delete( Database::table( 'translations' ), array( 'element_type' => $element_type, 'element_id' => absint( $element_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table delete followed by cache invalidation.
		self::invalidate( $element_type, $element_id, $row ? $row->group_uuid : '' );
	}

	private static function invalidate( $element_type, $element_id, $group_uuid = '' ) {
		$key = sanitize_key( $element_type ) . ':' . absint( $element_id );
		unset( self::$rows[ $key ] );
		wp_cache_delete( $key, 'openlingua_rows' );
		if ( $group_uuid ) {
			$group_key = sanitize_key( $element_type ) . ':' . $group_uuid;
			unset( self::$groups[ $group_key ] );
			wp_cache_delete( $group_key, 'openlingua_groups' );
		}
	}
}
