<?php
namespace OpenLingua;

defined( 'ABSPATH' ) || exit;

final class Languages {
	private static $current;

	public static function all() {
		$languages = get_option( 'openlingua_languages', array() );
		$languages = is_array( $languages ) ? $languages : array();
		if ( class_exists( 'OpenLingua\\Modules\\Language_Catalog' ) ) {
			$catalog = \OpenLingua\Modules\Language_Catalog::merged();
			foreach ( $languages as $code => $language ) { $languages[ $code ] = array_replace( $catalog[ $code ] ?? array(), $language ); }
		}
		$settings = (array) get_option( 'openlingua_language_settings', array() );
		$order = array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['language_order'] ?? array() ) ) ) );
		if ( $order ) {
			$positions = array_flip( $order );
			uksort( $languages, static function ( $left, $right ) use ( $positions ) {
				$left_position = $positions[ $left ] ?? PHP_INT_MAX;
				$right_position = $positions[ $right ] ?? PHP_INT_MAX;
				return $left_position === $right_position ? strcmp( $left, $right ) : $left_position <=> $right_position;
			} );
		}
		return $languages;
	}

	public static function public_all() {
		$languages = self::all();
		$settings = (array) get_option( 'openlingua_language_settings', array() );
		$hidden = array_map( 'sanitize_key', (array) ( $settings['hidden_languages'] ?? array() ) );
		return array_diff_key( $languages, array_flip( $hidden ) );
	}

	public static function default_code() {
		$default = sanitize_key( get_option( 'openlingua_default_language', 'en' ) );
		return isset( self::all()[ $default ] ) ? $default : (string) array_key_first( self::all() );
	}

	public static function current() {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$candidate = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::$current = isset( self::all()[ $candidate ] ) ? $candidate : self::default_code();
		return self::$current;
	}

	public static function set_current( $code ) {
		$code = sanitize_key( $code );
		if ( self::is_valid( $code ) ) {
			self::$current = $code;
		}
		return self::$current;
	}

	public static function is_valid( $code ) {
		return isset( self::all()[ sanitize_key( $code ) ] );
	}

	/** Returns the requested language followed by its configured, loop-safe fallbacks. */
	public static function fallback_chain( $code ) {
		$code = sanitize_key( $code );
		if ( ! self::is_valid( $code ) ) { $code = self::default_code(); }
		$settings = (array) get_option( 'openlingua_language_settings', array() );
		$fallbacks = (array) ( $settings['fallbacks'] ?? array() );
		$chain = array();
		while ( $code && self::is_valid( $code ) && ! in_array( $code, $chain, true ) ) {
			$chain[] = $code;
			$code = sanitize_key( $fallbacks[ $code ] ?? '' );
		}
		return $chain;
	}

	public static function url( $url, $code ) {
		$code = sanitize_key( $code );
		if ( ! self::is_valid( $code ) || ! $url ) {
			return $url;
		}
		if ( 0 === strpos( trim( (string) $url ), '#' ) ) {
			return $url;
		}

		$settings   = array_replace( array( 'url_mode' => 'directory', 'domains' => array() ), (array) get_option( 'openlingua_language_settings', array() ) );
		if ( 'query' === $settings['url_mode'] ) { return add_query_arg( 'lang', $code, remove_query_arg( 'lang', $url ) ); }
		$url        = remove_query_arg( 'lang', $url );
		$parts      = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );
		$source_parts = $home_parts;
		if ( 'domain' === $settings['url_mode'] ) {
			foreach ( (array) $settings['domains'] as $domain ) {
				$domain_parts = wp_parse_url( $domain );
				if ( ! empty( $parts['host'] ) && ! empty( $domain_parts['host'] ) && strtolower( $parts['host'] ) === strtolower( $domain_parts['host'] ) ) { $source_parts = $domain_parts; break; }
			}
			$target_base = $settings['domains'][ $code ] ?? '';
			if ( ! $target_base ) { return add_query_arg( 'lang', $code, $url ); }
		} elseif ( ! empty( $parts['host'] ) && ! empty( $home_parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
			return $url;
		}

		$home_path = isset( $source_parts['path'] ) ? '/' . trim( $source_parts['path'], '/' ) : '';
		$home_path = '/' === $home_path ? '' : rtrim( $home_path, '/' );
		$path      = isset( $parts['path'] ) ? $parts['path'] : '/';
		$relative  = 0 === strpos( $path, $home_path ) ? substr( $path, strlen( $home_path ) ) : $path;
		$codes     = array_map( 'preg_quote', array_keys( self::all() ) );
		$relative  = preg_replace( '#^/(?:' . implode( '|', $codes ) . ')(?=/|$)#', '', $relative );
		$target    = 'domain' === $settings['url_mode'] ? trailingslashit( $target_base ) . ltrim( $relative, '/' ) : home_url( '/' . $code . '/' . ltrim( $relative, '/' ) );
		if ( ! empty( $parts['query'] ) ) {
			$target .= '?' . $parts['query'];
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$target .= '#' . $parts['fragment'];
		}
		return $target;
	}
}
