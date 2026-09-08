<?php
namespace OpenLingua;

defined( 'ABSPATH' ) || exit;

/** Optional WPGraphQL compatibility. */
final class GraphQL {
	const LANGUAGE_ARG = 'openlinguaLanguage';

	public static function hooks() {
		add_action( 'graphql_register_types', array( __CLASS__, 'register_language_arguments' ) );
		add_filter( 'graphql_post_object_connection_query_args', array( __CLASS__, 'filter_post_query_args' ), 10, 5 );
		add_filter( 'graphql_term_object_connection_query_args', array( __CLASS__, 'filter_term_query_args' ), 10, 5 );
		// WPGraphQL sets suppress_filter on term connections. Apply the explicitly
		// requested OpenLingua language after its query arguments are assembled.
		add_filter( 'terms_clauses', array( __CLASS__, 'filter_term_clauses' ), 11, 3 );
		add_filter( 'graphql_resolve_field', array( __CLASS__, 'fallback_empty_property_id' ), 10, 9 );
	}

	/** Adds `where: { openlinguaLanguage: "es" }` to root and native taxonomy connections. */
	public static function register_language_arguments() {
		if ( ! function_exists( 'register_graphql_field' ) ) { return; }
		$post_types = get_post_types( array( 'show_in_graphql' => true ), 'objects' );
		$taxonomies = get_taxonomies( array( 'show_in_graphql' => true ), 'objects' );
		foreach ( $post_types as $post_type ) {
			$single = $post_type->graphql_single_name ?? '';
			if ( ! $single ) { continue; }
			$type_name = 'RootQueryTo' . ucfirst( $single ) . 'ConnectionWhereArgs';
			register_graphql_field( $type_name, self::LANGUAGE_ARG, array(
				'type'        => 'String',
				'description' => __( 'Limit content to an OpenLingua language code.', 'openlingua' ),
			) );
		}
		foreach ( $taxonomies as $taxonomy ) {
			$single = $taxonomy->graphql_single_name ?? '';
			if ( ! $single ) { continue; }
			$type_name = 'RootQueryTo' . ucfirst( $single ) . 'ConnectionWhereArgs';
			register_graphql_field( $type_name, self::LANGUAGE_ARG, array(
				'type'        => 'String',
				'description' => __( 'Limit taxonomy terms to an OpenLingua language code.', 'openlingua' ),
			) );
		}

		// The root `where` input is not reused by fields such as
		// `listing.statusListings(where: ...)`. Register the argument on each
		// native CPT-to-taxonomy connection as well, so it is discoverable in
		// GraphiQL and works for every taxonomy attached to a CPT.
		if ( ! function_exists( 'register_graphql_connection_where_arg' ) ) { return; }
		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy_type = $taxonomy->graphql_single_name ?? '';
			if ( ! $taxonomy_type ) { continue; }
			foreach ( (array) ( $taxonomy->object_type ?? array() ) as $post_type_name ) {
				$post_type = $post_types[ $post_type_name ] ?? null;
				$post_type_name = $post_type->graphql_single_name ?? '';
				if ( ! $post_type_name || 'attachment' === $post_type->name ) { continue; }
				register_graphql_connection_where_arg( ucfirst( $post_type_name ), ucfirst( $taxonomy_type ), self::LANGUAGE_ARG, array(
					'type'        => 'String',
					'description' => __( 'Limit taxonomy terms to an OpenLingua language code.', 'openlingua' ),
				) );
			}
		}
	}

	/** Marks WPGraphQL's secondary WP_Query so the normal language SQL filter applies. */
	public static function filter_post_query_args( $query_args, $source, $args, $context, $info ) {
		unset( $source, $context, $info );
		$post_types = array_map( 'sanitize_key', (array) ( $query_args['post_type'] ?? array() ) );
		if ( in_array( 'attachment', $post_types, true ) ) {
			return $query_args;
		}
		$requested = sanitize_key( $args['where'][ self::LANGUAGE_ARG ] ?? '' );
		$language  = Languages::is_valid( $requested ) ? $requested : self::request_language();
		$query_args['openlingua_language'] = $language;
		// Translations commonly have a newer post date than their original. Keep
		// the default listing order stable across languages unless the client has
		// deliberately requested a different ordering.
		if ( empty( $args['where']['orderby'] ) && empty( $args['where']['order'] ) ) {
			$query_args['openlingua_source_order'] = true;
		}
		return $query_args;
	}

	/** Marks WPGraphQL term connections so translated categories, tags, and custom terms match the requested language. */
	public static function filter_term_query_args( $query_args, $source, $args, $context, $info ) {
		unset( $context );
		// For `listing { statusListings { ... } }` and every other connected
		// taxonomy field, the post is already the language decision. Asking the
		// global term registry to filter it again breaks terms created by plugins
		// or imported before OpenLingua normalized their term groups.
		if ( self::source_post_id( $source ) || self::is_post_type_connection( $info ) ) {
			$query_args['openlingua_skip_language_filter'] = true;
			// WPGraphQL itself recognizes this query var. It also ensures the
			// standard OpenLingua term hook does not infer a request-wide language
			// for an already post-scoped connection.
			$query_args['suppress_filter'] = true;
			return $query_args;
		}
		$requested = sanitize_key( $args['where'][ self::LANGUAGE_ARG ] ?? '' );
		$language  = Languages::is_valid( $requested ) ? $requested : self::request_language();
		$query_args['openlingua_language'] = $language;
		return $query_args;
	}

	/** Limits only term queries explicitly marked by OpenLingua's WPGraphQL connection filter. */
	public static function filter_term_clauses( $clauses, $taxonomies, $args ) {
		unset( $taxonomies );
		if ( ! empty( $args['openlingua_skip_language_filter'] ) || ! empty( $args['object_ids'] ) || ! empty( $args['object_id'] ) || ! empty( $args['include'] ) ) {
			return $clauses;
		}
		$language = sanitize_key( $args['openlingua_language'] ?? '' );
		if ( ! Languages::is_valid( $language ) ) { return $clauses; }
		return Taxonomies::filter_language_clauses( $clauses, $language );
	}

	/**
	 * Keeps ACF property-name fields usable when a translation has an empty value.
	 *
	 * Some listing themes expose the display name through an ACF group such as
	 * `contentListing.overview.propertyId.content`, rather than the native post
	 * title. The translated post can therefore have a valid title while the card
	 * receives null. Resolve only that empty field from the translated post title.
	 */
	public static function fallback_empty_property_id( $result, $source, $args, $context, $info, $type_name, $field_key, $field, $field_resolver ) {
		unset( $args, $context, $info, $field, $field_resolver );
		// WPGraphQL can defer ACF field resolution. A Deferred is not a string and
		// must be returned untouched so WPGraphQL can resolve it later.
		if ( null !== $result && ( ! is_string( $result ) || '' !== trim( $result ) ) ) { return $result; }
		if ( 'content' !== $field_key || false === strpos( strtolower( (string) $type_name ), 'propertyid' ) ) { return $result; }
		$post_id = self::source_post_id( $source );
		$title   = $post_id && function_exists( 'get_the_title' ) ? get_the_title( $post_id ) : '';
		return is_string( $title ) && '' !== trim( $title ) ? $title : $result;
	}

	/** Finds the native post ID from the WPGraphQL/ACF field source. */
	private static function source_post_id( $source ) {
		foreach ( array( 'databaseId', 'ID', 'id', 'post_id', 'postId' ) as $key ) {
			$value = is_array( $source ) ? ( $source[ $key ] ?? 0 ) : ( is_object( $source ) ? ( $source->$key ?? 0 ) : 0 );
			if ( absint( $value ) ) { return absint( $value ); }
		}
		return 0;
	}

	/** Detects a post-to-taxonomy field even when WPGraphQL defers the post model. */
	private static function is_post_type_connection( $info ) {
		$parent_type = is_object( $info ) && isset( $info->parentType ) ? $info->parentType : null;
		$type_name   = is_object( $parent_type ) ? (string) ( $parent_type->name ?? '' ) : '';
		if ( '' === $type_name ) { return false; }
		foreach ( (array) get_post_types( array( 'show_in_graphql' => true ), 'objects' ) as $post_type ) {
			$graphql_name = (string) ( $post_type->graphql_single_name ?? '' );
			if ( $graphql_name && 0 === strcasecmp( $type_name, $graphql_name ) ) { return true; }
		}
		return false;
	}

	/**
	 * Resolves GraphQL language without requiring a client change for browser requests.
	 * Explicit query/header values win; the referring language URL is a safe fallback.
	 */
	public static function request_language() {
		$candidates = array(
			isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public GraphQL language hint, validated below.
			isset( $_SERVER['HTTP_X_OPENLINGUA_LANGUAGE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_OPENLINGUA_LANGUAGE'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public GraphQL request header, validated below.
		);
		foreach ( $candidates as $candidate ) {
			$candidate = sanitize_key( wp_unslash( $candidate ) );
			if ( Languages::is_valid( $candidate ) ) { return $candidate; }
		}

		$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
		$path    = (string) wp_parse_url( $referer, PHP_URL_PATH );
		$codes   = array_map( 'preg_quote', array_keys( Languages::all() ) );
		if ( $path && $codes && preg_match( '#/(?:' . implode( '|', $codes ) . ')(?=/|$)#', $path, $matches ) ) {
			$segments = array_values( array_filter( explode( '/', trim( $matches[0], '/' ) ) ) );
			$candidate = sanitize_key( end( $segments ) );
			if ( Languages::is_valid( $candidate ) ) { return $candidate; }
		}
		return Languages::current();
	}
}
