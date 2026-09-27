<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

final class Routing {
	private static $original_request_uri = '';
	private static $requested_language = '';

	public static function hooks() {
		self::detect_language();
		add_filter( 'do_parse_request', array( __CLASS__, 'detect_prefix' ), 1, 3 );
		add_filter( 'request', array( __CLASS__, 'resolve_public_term_slugs' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'resolve_language_object' ), 1 );
		add_filter( 'post_link', array( __CLASS__, 'post_url' ), 10, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'post_url' ), 10, 2 );
		add_filter( 'page_link', array( __CLASS__, 'page_url' ), 10, 2 );
		add_filter( 'term_link', array( __CLASS__, 'term_url' ), 10, 3 );
		add_filter( 'day_link', array( __CLASS__, 'archive_url' ) );
		add_filter( 'month_link', array( __CLASS__, 'archive_url' ) );
		add_filter( 'year_link', array( __CLASS__, 'archive_url' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_to_translation' ), 2 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 100 );
	}

	public static function archive_url( $url ) {
		return Languages::url( $url, Languages::current() );
	}

	/** Detects the requested language before WordPress initializes locale-dependent output. */
	public static function detect_language() {
		if ( is_admin() ) { return; }
		$settings = array_replace( array( 'url_mode' => 'directory', 'domains' => array() ), (array) get_option( 'openlingua_language_settings', array() ) );
		if ( 'query' === $settings['url_mode'] ) { Languages::current(); return; }
		if ( 'domain' === $settings['url_mode'] ) {
			$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
			foreach ( (array) $settings['domains'] as $code => $domain ) {
				$domain_host = strtolower( (string) wp_parse_url( $domain, PHP_URL_HOST ) );
				if ( $domain_host && $host === $domain_host ) { Languages::set_current( $code ); break; }
			}
			return;
		}
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base = '/' . trim( $home_path, '/' );
		$base = '/' === $base ? '' : $base;
		$relative = 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : $path;
		$codes = array_map( 'preg_quote', array_keys( Languages::all() ) );
		if ( $codes && preg_match( '#^/(' . implode( '|', $codes ) . ')(?=/|$)#', $relative, $matches ) ) { Languages::set_current( $matches[1] ); }
	}

	public static function detect_prefix( $do_parse, $wp, $extra_query_vars ) {
		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return $do_parse;
		}
		$settings = array_replace( array( 'url_mode' => 'directory', 'domains' => array() ), (array) get_option( 'openlingua_language_settings', array() ) );
		if ( 'query' === $settings['url_mode'] ) { return $do_parse; }
		if ( 'domain' === $settings['url_mode'] ) {
			$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
			foreach ( (array) $settings['domains'] as $code => $domain ) {
				$domain_host = strtolower( (string) wp_parse_url( $domain, PHP_URL_HOST ) );
				if ( $domain_host && $host === $domain_host ) { Languages::set_current( $code ); break; }
			}
			return $do_parse;
		}
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path   = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base        = '/' . trim( $home_path, '/' );
		$base        = '/' === $base ? '' : $base;
		$relative    = 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : $path;
		$codes       = array_map( 'preg_quote', array_keys( Languages::all() ) );
		if ( ! $codes || ! preg_match( '#^/(' . implode( '|', $codes ) . ')(?=/|$)#', $relative, $matches ) ) {
			return $do_parse;
		}
		Languages::set_current( $matches[1] );
		self::$original_request_uri = $request_uri;
		self::$requested_language = sanitize_key( $matches[1] );
		$stripped = preg_replace( '#^/' . preg_quote( $matches[1], '#' ) . '(?=/|$)#', '', $relative );
		$query    = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
		$_SERVER['REQUEST_URI'] = $base . ( $stripped ?: '/' ) . ( $query ? '?' . $query : '' );
		return $do_parse;
	}

	public static function resolve_language_object( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->get( 'suppress_filters' ) ) { return; }
		$resolved_id = absint( $query->get( 'page_id' ) ?: $query->get( 'p' ) );
		if ( $resolved_id ) {
			$row = Translations::row( 'post', $resolved_id );
			$translated_id = $row && $row->language !== Languages::current() ? Translations::translated_id_with_fallback( 'post', $resolved_id, Languages::current() ) : 0;
			if ( $translated_id ) {
				self::set_resolved_post( $query, $translated_id, get_post_type( $translated_id ) );
			}
			return;
		}
		$pagename = trim( (string) $query->get( 'pagename' ), '/' );
		$name = sanitize_title( (string) $query->get( 'name' ) );
		if ( ! $pagename && ! $name ) { return; }

		$post_types = $pagename ? array( 'page' ) : (array) $query->get( 'post_type' );
		if ( ! $post_types ) { $post_types = get_post_types( array( 'public' => true ), 'names' ); }
		if ( in_array( 'any', $post_types, true ) ) { $post_types = get_post_types( array( 'public' => true ), 'names' ); }
		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		if ( ! $post_types ) { return; }

		$slug = sanitize_title( $pagename ? basename( $pagename ) : $name );
		global $wpdb;
		$table = Database::table( 'translations' );
		$cache_key = 'route:' . md5( Languages::current() . '|' . $slug . '|' . implode( ',', $post_types ) );
		$candidates = wp_cache_get( $cache_key, 'openlingua_routes', false, $found );
		if ( ! $found ) {
			// This lookup joins SysOpenLang's relationship table, so no WordPress query API can express it.
			$candidates = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT p.ID, p.post_type FROM %i p INNER JOIN %i ol_route ON ol_route.element_type = 'post' AND ol_route.element_id = p.ID WHERE ol_route.language = %s AND p.post_name = %s AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID",
					$wpdb->posts,
					$table,
					Languages::current(),
					$slug
				)
			);
			$candidates = array_values( array_filter( $candidates, static function ( $candidate ) use ( $post_types ) { return in_array( $candidate->post_type, $post_types, true ); } ) );
			wp_cache_set( $cache_key, $candidates, 'openlingua_routes', HOUR_IN_SECONDS );
		}
		foreach ( $candidates as $candidate ) {
			if ( $pagename && trim( get_page_uri( $candidate->ID ), '/' ) !== $pagename ) { continue; }
			self::set_resolved_post( $query, $candidate->ID, $candidate->post_type );
			return;
		}
	}

	private static function set_resolved_post( $query, $post_id, $post_type ) {
		$post_id   = absint( $post_id );
		$post_type = sanitize_key( $post_type );
		$post      = get_post( $post_id );
		if ( ! $post ) { return; }

		if ( 'page' === $post_type ) {
			$query->set( 'page_id', $post_id );
			$query->set( 'p', 0 );
		} else {
			$query->set( 'p', $post_id );
			$query->set( 'page_id', 0 );
			$query->set( 'post_type', $post_type );
		}
		$query->set( 'name', '' );
		$query->set( 'pagename', '' );

		// WP_Query resolves pagename before pre_get_posts. Keep its cached object
		// synchronized so canonical redirects and templates use this language.
		$query->queried_object    = $post;
		$query->queried_object_id = $post_id;
	}

	public static function redirect_canonical( $redirect_url, $requested_url ) {
		if ( ! self::$original_request_uri || ! self::$requested_language || self::$requested_language !== Languages::current() ) { return $redirect_url; }
		if ( is_singular() ) {
			$row = Translations::row( 'post', get_queried_object_id() );
			$expected = $row && $row->language === self::$requested_language ? get_permalink( get_queried_object_id() ) : '';
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$row = Translations::row( 'term', get_queried_object_id() );
			$expected = $row && $row->language === self::$requested_language ? get_term_link( get_queried_object_id() ) : '';
		} else {
			return $redirect_url;
		}
		if ( ! $expected || is_wp_error( $expected ) ) { return $redirect_url; }
		$requested_path = (string) wp_parse_url( self::$original_request_uri, PHP_URL_PATH );
		$expected_path = (string) wp_parse_url( $expected, PHP_URL_PATH );
		return $requested_path === $expected_path ? false : $expected;
	}

	public static function post_url( $url, $post ) {
		$post = get_post( $post );
		if ( ! $post ) { return $url; }
		$row = Translations::row( 'post', $post->ID );
		return $row ? Languages::url( $url, $row->language ) : $url;
	}

	public static function page_url( $url, $post_id ) {
		return self::post_url( $url, get_post( $post_id ) );
	}

	public static function term_url( $url, $term, $taxonomy ) {
		if ( ! is_object( $term ) || empty( $term->term_id ) ) { return $url; }
		$row = Translations::row( 'term', $term->term_id );
		$language = is_admin() ? ( $row->language ?? Languages::default_code() ) : Languages::current();
		$target_id = Taxonomies::term_id_for_language( $term->term_id, $language );
		$target = $target_id === (int) $term->term_id ? $term : get_term( $target_id, $taxonomy );
		if ( ! $target || is_wp_error( $target ) ) { $target = $term; }
		$public_slug = Taxonomies::public_slug( $target );
		if ( $target->term_id !== $term->term_id || $public_slug !== $term->slug ) { $url = self::replace_term_slug( $url, $term->slug, $public_slug ); }
		return Languages::url( $url, $language );
	}

	/** Maps a public language term slug back to its unique physical WordPress slug before the main query runs. */
	public static function resolve_public_term_slugs( $query_vars ) {
		if ( is_admin() || ! is_array( $query_vars ) ) { return $query_vars; }
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$query_var = true === ( $taxonomy->query_var ?? false ) ? $taxonomy->name : ( $taxonomy->query_var ?? '' );
			if ( ! $query_var || empty( $query_vars[ $query_var ] ) || false !== strpos( (string) $query_vars[ $query_var ], '/' ) ) { continue; }
			$term = Taxonomies::term_for_public_slug( $taxonomy->name, $query_vars[ $query_var ], Languages::current() );
			if ( $term ) { $query_vars[ $query_var ] = $term->slug; }
		}
		return $query_vars;
	}

	private static function replace_term_slug( $url, $from, $to ) {
		$from = rawurlencode( (string) $from );
		$to = rawurlencode( (string) $to );
		return $from && $to ? str_replace( '/' . $from . '/', '/' . $to . '/', $url ) : $url;
	}

	public static function redirect_to_translation() {
		if ( is_preview() ) { return; }
		if ( is_singular() ) {
			$element_type = 'post';
			$element_id   = get_queried_object_id();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$element_type = 'term';
			$element_id   = get_queried_object_id();
		} else {
			return;
		}
		$row = Translations::row( $element_type, $element_id );
		if ( ! $row || $row->language === Languages::current() ) { return; }
		$translated_id = Translations::translated_id_with_fallback( $element_type, $element_id, Languages::current() );
		if ( 'post' === $element_type ) {
			$target = get_permalink( $translated_id ?: $element_id );
		} else {
			$target = get_term_link( $translated_id ?: $element_id );
		}
		if ( ! is_wp_error( $target ) ) {
			$current_path = (string) wp_parse_url( self::$original_request_uri ?: sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
			$target_path  = (string) wp_parse_url( $target, PHP_URL_PATH );
			if ( untrailingslashit( $current_path ) === untrailingslashit( $target_path ) ) { return; }
			wp_safe_redirect( $target, 302 );
			exit;
		}
	}

	public static function maybe_flush_rewrite_rules() {
		if ( get_option( 'openlingua_flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'openlingua_flush_rewrite_rules' );
		}
	}
}
