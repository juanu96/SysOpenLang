<?php
namespace OpenLingua;

defined( 'ABSPATH' ) || exit;

final class Taxonomies {
	const PUBLIC_SLUG_META = '_openlingua_public_slug';

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'register_fields' ), 99 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 16 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'created_term', array( __CLASS__, 'save' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'save' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'delete' ), 10, 1 );
		add_action( 'pre_get_terms', array( __CLASS__, 'prepare_frontend_term_query' ), 1 );
		add_filter( 'terms_clauses', array( __CLASS__, 'filter_frontend_term_clauses' ), 10, 3 );
		add_filter( 'wp_unique_term_slug', array( __CLASS__, 'capture_public_slug' ), 10, 3 );
		add_action( 'admin_post_openlingua_duplicate_term', array( __CLASS__, 'duplicate' ) );
		add_action( 'admin_post_openlingua_save_term_translation', array( __CLASS__, 'save_translation' ) );
	}

	/**
	 * Gives public root-term queries an explicit set of language-eligible IDs.
	 *
	 * WordPress resolves a root taxonomy connection through WP_Term_Query. Some
	 * third-party query layers (including current WPGraphQL versions) suppress
	 * the normal term filters while constructing that query. Supplying the IDs
	 * here keeps their selects and archives language-scoped without changing the
	 * physical relationships assigned to a translated post.
	 */
	public static function prepare_frontend_term_query( $query ) {
		if ( ! $query instanceof \WP_Term_Query ) { return; }
		$args = (array) $query->query_vars;
		if ( ! empty( $args['object_ids'] ) || ! empty( $args['object_id'] ) || ! empty( $args['include'] ) ) { return; }

		$requested = sanitize_key( $args['openlingua_language'] ?? '' );
		if ( ! $requested && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$requested = sanitize_key( wp_unslash( $_GET['lang'] ?? $_SERVER['HTTP_X_OPENLINGUA_LANGUAGE'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only language selector.
		}
		if ( is_admin() && ! Languages::is_valid( $requested ) ) { return; }
		$language = Languages::is_valid( $requested ) ? $requested : Languages::current();
		if ( ! Languages::is_valid( $language ) || Languages::default_code() === $language ) { return; }

		$ids = self::language_term_ids( $language );
		if ( $ids ) { $query->query_vars['include'] = $ids; }
	}

	/** Returns translated terms plus safe defaults for a secondary language. */
	private static function language_term_ids( $language ) {
		$language = sanitize_key( $language );
		if ( ! Languages::is_valid( $language ) || Languages::default_code() === $language ) { return array(); }
		global $wpdb;
		$table = Database::table( 'translations' );
		$term_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT t.term_id
			FROM %i t
			INNER JOIN %i tt ON tt.term_id = t.term_id
			LEFT JOIN %i ol_target ON ol_target.element_type = 'term' AND ol_target.element_id = t.term_id AND ol_target.language = %s
			LEFT JOIN %i ol_any ON ol_any.element_type = 'term' AND ol_any.element_id = t.term_id
			LEFT JOIN %i ol_default ON ol_default.element_type = 'term' AND ol_default.element_id = t.term_id AND ol_default.language = %s
			LEFT JOIN %i ol_group_target ON ol_group_target.element_type = 'term' AND ol_group_target.group_uuid = ol_default.group_uuid AND ol_group_target.language = %s
			LEFT JOIN %i tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			LEFT JOIN %i ol_post ON ol_post.element_type = 'post' AND ol_post.element_id = tr.object_id AND ol_post.language = %s
			WHERE ol_target.id IS NOT NULL OR ol_any.id IS NULL OR (ol_default.id IS NOT NULL AND ol_group_target.id IS NULL) OR ol_post.id IS NOT NULL",
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$table,
			$language,
			$table,
			$table,
			Languages::default_code(),
			$table,
			$language,
			$wpdb->term_relationships,
			$table,
			$language
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Language relationship lookup for WP_Term_Query compatibility.
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $term_ids ) ) ) );
	}

	/** Limits public get_terms() calls to the current language for all themes and plugins. */
	public static function filter_frontend_term_clauses( $clauses, $taxonomies, $args ) {
		unset( $taxonomies );
		// A relationship query (REST's post fields, WPGraphQL connected terms and
		// most builder modules) already has a language-scoped post. Filtering its
		// terms again through OpenLingua's term registry can hide valid legacy or
		// third-party terms whose relationship exists but whose term group has not
		// been normalized yet. Return the physical assignments unchanged; root
		// taxonomy queries still receive the language constraint below.
		// Explicit IDs are a completed selection (for example WPGraphQL's
		// deferred term loader). Re-filtering that list using request language
		// would hide valid translated terms while the loader hydrates them.
		if ( ! empty( $args['object_ids'] ) || ! empty( $args['object_id'] ) || ! empty( $args['include'] ) ) {
			return $clauses;
		}
		$requested = sanitize_key( $args['openlingua_language'] ?? '' );
		if ( ! $requested && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$requested = sanitize_key( wp_unslash( $_GET['lang'] ?? $_SERVER['HTTP_X_OPENLINGUA_LANGUAGE'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only language selector.
		}
		$admin_list_language = self::admin_term_list_language( $args );
		if ( $admin_list_language ) {
			return self::filter_language_clauses( $clauses, $admin_list_language );
		}
		// WPGraphQL intentionally suppresses ordinary term filters. An explicit
		// OpenLingua language request must still be honored; otherwise connected
		// custom-taxonomy fields are silently empty in every secondary language.
		$has_explicit_language = Languages::is_valid( $requested );
		if ( ( ! empty( $args['suppress_filter'] ) && ! $has_explicit_language ) || ! empty( $args['openlingua_skip_language_filter'] ) || ( is_admin() && ! $has_explicit_language ) ) {
			return $clauses;
		}
		$language = Languages::is_valid( $requested ) ? $requested : Languages::current();
		return self::filter_language_clauses( $clauses, $language );
	}

	/** Returns the active language for the native taxonomy list table, or empty when it should stay unfiltered. */
	private static function admin_term_list_language( $args ) {
		if ( ! is_admin() || ! empty( $args['openlingua_skip_language_filter'] ) ) { return ''; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit-tags' !== ( $screen->base ?? '' ) ) { return ''; }
		if ( ! $screen ) {
			global $pagenow;
			if ( 'edit-tags.php' !== ( $pagenow ?? '' ) ) { return ''; }
		}
		$language = class_exists( __NAMESPACE__ . '\Admin' ) ? Admin::content_language() : Languages::default_code();
		if ( 'all' === $language || ! Languages::is_valid( $language ) ) { return ''; }
		return $language;
	}

	/** Adds the language constraint shared by normal WordPress and WPGraphQL term queries. */
	public static function filter_language_clauses( $clauses, $language ) {
		$language = sanitize_key( $language );
		if ( ! Languages::is_valid( $language ) ) { return $clauses; }
		global $wpdb;
		$table = Database::table( 'translations' );
		if ( Languages::default_code() === $language ) {
			$unassigned = $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM %i ol_term_any WHERE ol_term_any.element_type = 'term' AND ol_term_any.element_id = t.term_id)", $table );
			$translated = $wpdb->prepare( "EXISTS (SELECT 1 FROM %i ol_term_lang WHERE ol_term_lang.element_type = 'term' AND ol_term_lang.element_id = t.term_id AND ol_term_lang.language = %s)", $table, $language );
			$clauses['where'] .= ' AND (' . $unassigned . ' OR ' . $translated . ')';
		} else {
			// A translated term wins. Until it exists, retain the default-language
			// term as a fallback so every taxonomy is usable in a new language.
			$translated = $wpdb->prepare( "EXISTS (SELECT 1 FROM %i ol_term_lang WHERE ol_term_lang.element_type = 'term' AND ol_term_lang.element_id = t.term_id AND ol_term_lang.language = %s)", $table, $language );
			$unassigned = $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM %i ol_term_any WHERE ol_term_any.element_type = 'term' AND ol_term_any.element_id = t.term_id)", $table );
			$default_fallback = $wpdb->prepare( "EXISTS (SELECT 1 FROM %i ol_term_default WHERE ol_term_default.element_type = 'term' AND ol_term_default.element_id = t.term_id AND ol_term_default.language = %s) AND NOT EXISTS (SELECT 1 FROM %i ol_term_target WHERE ol_term_target.element_type = 'term' AND ol_term_target.group_uuid = ol_term_default.group_uuid AND ol_term_target.language = %s)", $table, Languages::default_code(), $table, $language );
			// Legacy sites can have a valid target-language term physically assigned
			// to translated posts while the old term relationship row is missing or
			// stale. Keep that term visible to core archives, WPGraphQL and third-party
			// get_terms() consumers. EXISTS avoids duplicate terms and only admits a
			// term when its related post is explicitly in the requested language.
			$assigned_to_language = $wpdb->prepare( "EXISTS (SELECT 1 FROM %i ol_term_relationship INNER JOIN %i ol_term_post_language ON ol_term_post_language.element_type = 'post' AND ol_term_post_language.element_id = ol_term_relationship.object_id AND ol_term_post_language.language = %s WHERE ol_term_relationship.term_taxonomy_id = tt.term_taxonomy_id)", $wpdb->term_relationships, $table, $language );
			$clauses['where'] .= ' AND (' . $translated . ' OR ' . $unassigned . ' OR (' . $default_fallback . ') OR ' . $assigned_to_language . ')';
		}
		return $clauses;
	}

	/** Returns the preferred term for a language, falling back to the default-language term. */
	public static function term_id_for_language( $term_id, $language ) {
		$term_id = absint( $term_id );
		$language = sanitize_key( $language );
		if ( ! $term_id || ! Languages::is_valid( $language ) ) { return $term_id; }
		$translated = Translations::translated_id( 'term', $term_id, $language );
		if ( $translated ) { return $translated; }
		$default = Translations::translated_id( 'term', $term_id, Languages::default_code() );
		return $default ?: $term_id;
	}

	/** Returns the language-scoped public slug, independent of WordPress's physical term slug. */
	public static function public_slug( $term ) {
		$term = is_object( $term ) ? $term : get_term( $term );
		if ( ! $term || is_wp_error( $term ) ) { return ''; }
		$slug = sanitize_title( get_term_meta( $term->term_id, self::PUBLIC_SLUG_META, true ) );
		return $slug ?: $term->slug;
	}

	/** Resolves a language-scoped public slug to its physical WordPress term. */
	public static function term_for_public_slug( $taxonomy, $slug, $language ) {
		$taxonomy = sanitize_key( $taxonomy );
		$slug     = sanitize_title( $slug );
		$language = sanitize_key( $language );
		if ( ! $taxonomy || ! $slug || ! Languages::is_valid( $language ) ) { return null; }
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'meta_key' => self::PUBLIC_SLUG_META, 'meta_value' => $slug, 'openlingua_language' => $language ) );
		if ( is_wp_error( $terms ) ) { return null; }
		foreach ( $terms as $term ) {
			$row = Translations::row( 'term', $term->term_id );
			if ( $row && $language === $row->language ) { return $term; }
		}
		return null;
	}

	/** Stores the requested native-editor slug as the public language URL when WordPress has to suffix its physical slug. */
	public static function capture_public_slug( $slug, $term, $original_slug ) {
		if ( ! is_object( $term ) || empty( $term->term_id ) ) { return $slug; }
		$row = Translations::row( 'term', $term->term_id );
		$requested = sanitize_title( $original_slug );
		if ( $row && $row->language !== Languages::default_code() && $requested ) { update_term_meta( $term->term_id, self::PUBLIC_SLUG_META, $requested ); }
		return $slug;
	}

	/**
	 * Makes a translated post use the equivalent term from every source taxonomy.
	 *
	 * Term relationships are WordPress data, separate from the post translation
	 * group. Existing translations created before term synchronization was
	 * available can therefore retain source-language term IDs. Replacing the
	 * relationship set is intentional: it keeps the translated post and its
	 * source on the same taxonomy groups, in the destination language.
	 *
	 * @return true|\WP_Error
	 */
	public static function synchronize_post_terms( $source_post_id, $target_post_id, $language ) {
		$source_post_id = absint( $source_post_id );
		$target_post_id = absint( $target_post_id );
		$language       = sanitize_key( $language );
		$source         = $source_post_id ? get_post( $source_post_id ) : null;
		$target         = $target_post_id ? get_post( $target_post_id ) : null;
		if ( ! $source || ! $target || $source->post_type !== $target->post_type || ! Languages::is_valid( $language ) ) {
			return new \WP_Error( 'openlingua_invalid_post_term_sync', __( 'Invalid post taxonomy synchronization request.', 'openlingua' ) );
		}

		global $wpdb;
		// A taxonomy can have valid native relationships even when a third-party
		// plugin forgot to register that taxonomy against the post type. Core's
		// get_object_taxonomies() then omits it, which used to make a successful
		// repair silently clear the translated post's relationships. Discover the
		// actual relationship taxonomies first and merge the registered list.
		$relationship_taxonomies = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT tt.taxonomy FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d",
			$wpdb->term_relationships,
			$wpdb->term_taxonomy,
			$source_post_id
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Explicit maintenance recovery of native post-term relationships.
		$taxonomies = array_unique( array_merge( (array) get_object_taxonomies( $source->post_type ), array_filter( array_map( 'sanitize_key', (array) $relationship_taxonomies ) ) ) );

		foreach ( $taxonomies as $taxonomy ) {
			// Read the relationship table directly. It is the canonical record and
			// bypasses filters used by GraphQL, builders and listing plugins.
			$term_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT tt.term_id FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s",
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$source_post_id,
				$taxonomy
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Explicit maintenance recovery of native post-term relationships.
			if ( null === $term_ids ) {
				$term_ids = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $term_ids ) ) { return $term_ids; }
			}
			$translated_terms = array();
			$valid_source_terms = 0;
			foreach ( $term_ids as $term_id ) {
				// Direct relationship-table reads can reveal legacy rows whose term
				// was deleted by WordPress or a third-party plugin. That row is not
				// a source taxonomy assignment and must not make a page uneditable.
				$source_term = get_term( $term_id, $taxonomy );
				if ( ! $source_term || is_wp_error( $source_term ) ) { continue; }
				$valid_source_terms++;
				$translated_id = self::ensure_translation( $term_id, $language );
				if ( is_wp_error( $translated_id ) ) { return $translated_id; }
				$translated_terms[] = absint( $translated_id );
			}
			// Do not let a taxonomy made solely of orphaned legacy rows erase a
			// target relationship set. An intentional empty source taxonomy still
			// reaches wp_set_object_terms() because its $term_ids is empty.
			if ( $term_ids && ! $valid_source_terms ) { continue; }
			$result = wp_set_object_terms( $target_post_id, $translated_terms, $taxonomy, false );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}

	/**
	 * Repairs legacy post-to-term relationships in bounded batches.
	 *
	 * Only translation groups with a post in the configured default language are
	 * considered. This makes the source deterministic and avoids changing groups
	 * that were imported without a canonical original.
	 *
	 * @return array{synced:int,failed:int,skipped:int}
	 */
	public static function synchronize_existing_post_terms( $limit = 250 ) {
		global $wpdb;
		$limit   = max( 1, min( 1000, absint( $limit ) ) );
		$table   = Database::table( 'translations' );
		$default = Languages::default_code();
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT source.element_id AS source_id, target.element_id AS target_id, target.language AS target_language FROM %i source INNER JOIN %i target ON target.element_type = 'post' AND target.group_uuid = source.group_uuid WHERE source.element_type = 'post' AND source.language = %s AND target.language <> %s ORDER BY target.id ASC LIMIT %d",
			$table,
			$table,
			$default,
			$default,
			$limit
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Maintenance repair across OpenLingua's relationship table.
		$summary = array( 'synced' => 0, 'failed' => 0, 'skipped' => 0 );
		foreach ( (array) $rows as $row ) {
			$result = self::synchronize_post_terms( $row->source_id, $row->target_id, $row->target_language );
			if ( true === $result ) { $summary['synced']++; }
			elseif ( is_wp_error( $result ) ) { $summary['failed']++; }
			else { $summary['skipped']++; }
		}
		return $summary;
	}

	/**
	 * Adds an existing term to the same translation group as its original.
	 *
	 * This is deliberately explicit. Terms created independently in different
	 * languages cannot be paired safely from their name or slug: both values
	 * can be valid but refer to different concepts. The method keeps the target
	 * term, its WordPress ID, metadata and public URL intact, and changes only
	 * OpenLingua's relationship record.
	 *
	 * @return true|\WP_Error
	 */
	public static function link_existing_translation( $source_term_id, $target_term_id, $language ) {
		$source_term_id = absint( $source_term_id );
		$target_term_id = absint( $target_term_id );
		$language       = sanitize_key( $language );
		$source         = $source_term_id ? get_term( $source_term_id ) : null;
		$target         = $target_term_id ? get_term( $target_term_id ) : null;
		if ( ! $source || ! $target || is_wp_error( $source ) || is_wp_error( $target ) || $source_term_id === $target_term_id || $source->taxonomy !== $target->taxonomy || ! Languages::is_valid( $language ) ) {
			return new \WP_Error( 'openlingua_invalid_term_link', __( 'Invalid taxonomy translation link.', 'openlingua' ) );
		}

		$source_row = Translations::row( 'term', $source_term_id );
		if ( ! $source_row ) {
			$group = Translations::assign( 'term', $source_term_id, Languages::default_code() );
			if ( is_wp_error( $group ) ) { return $group; }
			$source_row = Translations::row( 'term', $source_term_id );
		}
		if ( ! $source_row || $source_row->language === $language ) {
			return new \WP_Error( 'openlingua_invalid_term_link_language', __( 'A translation must use a different language from its original term.', 'openlingua' ) );
		}

		$existing = Translations::translated_id( 'term', $source_term_id, $language );
		if ( $existing && $existing !== $target_term_id ) {
			return new \WP_Error( 'openlingua_term_translation_exists', __( 'This original term already has a translation in that language.', 'openlingua' ) );
		}
		$assigned = Translations::assign( 'term', $target_term_id, $language, $source_row->group_uuid, $source_row->language ?: Languages::default_code() );
		return is_wp_error( $assigned ) ? $assigned : true;
	}

	/**
	 * Creates or returns the term belonging to a translation group in $language.
	 * Parents are resolved first, so a translated child can never point to a
	 * parent from another language. New terms deliberately retain the source
	 * name; a proper name is safer to preserve than to translate automatically.
	 *
	 * @return int|\WP_Error
	 */
	public static function ensure_translation( $term_id, $language, array $ancestry = array() ) {
		$term_id = absint( $term_id );
		$language = sanitize_key( $language );
		if ( ! $term_id || ! Languages::is_valid( $language ) ) { return new \WP_Error( 'openlingua_invalid_term_translation', __( 'Invalid term translation request.', 'openlingua' ) ); }
		if ( in_array( $term_id, $ancestry, true ) ) { return new \WP_Error( 'openlingua_term_hierarchy_cycle', __( 'A taxonomy hierarchy cannot contain a cycle.', 'openlingua' ) ); }
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) { return new \WP_Error( 'openlingua_term_not_found', __( 'Source term not found.', 'openlingua' ) ); }
		$row = Translations::row( 'term', $term_id );
		if ( ! $row ) {
			$group = Translations::assign( 'term', $term_id, Languages::default_code() );
			if ( is_wp_error( $group ) ) { return $group; }
			$row = Translations::row( 'term', $term_id );
		} else {
			$group = $row->group_uuid;
		}
		if ( ! $row ) { return new \WP_Error( 'openlingua_term_link_failed', __( 'The source term could not be linked to a language.', 'openlingua' ) ); }
		$target_id = Translations::translated_id( 'term', $term_id, $language );
		$parent_id = 0;
		if ( $term->parent ) {
			// A deleted parent leaves an orphaned but otherwise valid term. Keep
			// that term usable as a root rather than failing every related post
			// save. A real, existing parent still recurses and is synchronized.
			$parent = get_term( $term->parent, $term->taxonomy );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$parent_id = self::ensure_translation( $term->parent, $language, array_merge( $ancestry, array( $term_id ) ) );
				if ( is_wp_error( $parent_id ) ) { return $parent_id; }
			}
		}
		if ( $target_id ) {
			$target = get_term( $target_id, $term->taxonomy );
			if ( $target && ! is_wp_error( $target ) && (int) $target->parent !== (int) $parent_id ) {
				$updated = wp_update_term( $target_id, $term->taxonomy, array( 'parent' => $parent_id ) );
				if ( is_wp_error( $updated ) ) { return $updated; }
			}
			return absint( $target_id );
		}
		$result = wp_insert_term( $term->name, $term->taxonomy, array(
			'description' => $term->description,
			'parent'      => $parent_id,
			'slug'        => self::unique_translation_slug( $term->slug, $term->taxonomy, $language ),
		) );
		if ( is_wp_error( $result ) ) { return $result; }
		$target_id = absint( $result['term_id'] );
		foreach ( get_term_meta( $term_id ) as $key => $values ) {
			foreach ( $values as $value ) { add_term_meta( $target_id, $key, maybe_unserialize( $value ) ); }
		}
		$assigned = Translations::assign( 'term', $target_id, $language, $row->group_uuid, $row->language ?: Languages::default_code() );
		return is_wp_error( $assigned ) ? $assigned : $target_id;
	}

	/**
	 * Returns a physical WordPress term slug that cannot collide in its taxonomy.
	 * The language suffix is deterministic, while a numeric suffix handles terms
	 * that were created independently before their translation relationship.
	 */
	public static function unique_translation_slug( $slug, $taxonomy, $language, $exclude_term_id = 0 ) {
		$base = sanitize_title( $slug );
		$taxonomy = sanitize_key( $taxonomy );
		$language = sanitize_key( $language );
		$exclude_term_id = absint( $exclude_term_id );
		if ( ! $base || ! $taxonomy || ! $language ) { return $base; }
		global $wpdb;
		for ( $attempt = 0; $attempt < 1000; $attempt++ ) {
			$candidate = 0 === $attempt ? $base : $base . '-' . $language . ( $attempt > 1 ? '-' . $attempt : '' );
			$existing_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT tt.term_id FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s LIMIT 1", $wpdb->terms, $wpdb->term_taxonomy, $candidate, $taxonomy ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Core lookups may be language-filtered; physical slug uniqueness must not be.
			if ( ! $existing_id || $existing_id === $exclude_term_id ) { return $candidate; }
		}
		return $base . '-' . $language . '-' . wp_generate_password( 8, false, false );
	}

	public static function admin_menu() {
		add_submenu_page( 'openlingua', __( 'Taxonomy translations', 'openlingua' ), __( 'Taxonomies', 'openlingua' ), 'manage_categories', 'openlingua-taxonomies', array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'openlingua_page_openlingua-taxonomies' !== $hook ) { return; }
		wp_enqueue_style( 'openlingua-admin-taxonomies', plugins_url( 'assets/admin-taxonomies.css', OPENLINGUA_FILE ), array( 'dashicons' ), OPENLINGUA_VERSION );
		wp_enqueue_script( 'openlingua-admin-taxonomies', plugins_url( 'assets/admin-taxonomies.js', OPENLINGUA_FILE ), array(), OPENLINGUA_VERSION, true );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_categories' ) ) { return; }
		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $taxonomies[ $taxonomy ] ) ) { $taxonomy = $taxonomies ? array_key_first( $taxonomies ) : ''; }
		$query_taxonomies = $taxonomy ? array( $taxonomy ) : array();
		$terms = $query_taxonomies ? get_terms( array( 'taxonomy' => $query_taxonomies, 'hide_empty' => false, 'search' => $search, 'orderby' => 'name', 'order' => 'ASC' ) ) : array();
		if ( is_wp_error( $terms ) ) { $terms = array(); }
		$default = Languages::default_code();
		$terms = array_values( array_filter( $terms, static function ( $term ) use ( $default ) {
			$row = Translations::row( 'term', $term->term_id );
			return ! $row || $default === $row->language;
		} ) );
		$total = count( $terms );
		$per_page = 25;
		$terms = array_slice( $terms, ( $paged - 1 ) * $per_page, $per_page );
		$return_to = add_query_arg( array_filter( array( 'page' => 'openlingua-taxonomies', 'taxonomy' => $taxonomy, 's' => $search, 'paged' => $paged ) ), admin_url( 'admin.php' ) );

		echo '<div class="wrap openlingua-taxonomies"><h1>' . esc_html__( 'Taxonomy translations', 'openlingua' ) . '</h1><p class="description">' . esc_html__( 'Translate term names, URL slugs and descriptions from one compact screen.', 'openlingua' ) . '</p>';
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Taxonomy translation saved.', 'openlingua' ) . '</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<form method="get" class="openlingua-taxonomies__filters"><input type="hidden" name="page" value="openlingua-taxonomies"><label><span class="screen-reader-text">' . esc_html__( 'Search terms', 'openlingua' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search terms', 'openlingua' ) . '"></label><label><span class="screen-reader-text">' . esc_html__( 'Filter by taxonomy', 'openlingua' ) . '</span><select name="taxonomy">';
		foreach ( $taxonomies as $name => $object ) { echo '<option value="' . esc_attr( $name ) . '" ' . selected( $taxonomy, $name, false ) . '>' . esc_html( $object->labels->singular_name . ' (' . $name . ')' ) . '</option>'; }
		echo '</select></label>'; submit_button( __( 'Filter', 'openlingua' ), 'secondary', '', false );
		if ( $search ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'openlingua-taxonomies', 'taxonomy' => $taxonomy ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Clear', 'openlingua' ) . '</a>'; }
		echo '</form><table class="wp-list-table widefat fixed striped"><thead><tr><th class="column-term">' . esc_html__( 'Original term', 'openlingua' ) . '</th><th class="column-taxonomy">' . esc_html__( 'Taxonomy', 'openlingua' ) . '</th><th class="column-description">' . esc_html__( 'Description', 'openlingua' ) . '</th>';
		foreach ( Languages::all() as $code => $language ) { if ( $code !== $default ) { echo '<th class="column-language"><span title="' . esc_attr( $language['name'] ) . '">' . esc_html( $language['flag'] ?? strtoupper( $code ) ) . '</span></th>'; } }
		echo '</tr></thead><tbody>';
		if ( ! $terms ) { echo '<tr class="no-items"><td colspan="' . absint( 3 + max( 0, count( Languages::all() ) - 1 ) ) . '">' . esc_html__( 'No terms found.', 'openlingua' ) . '</td></tr>'; }
		foreach ( $terms as $term ) {
			$group = Translations::group( 'term', $term->term_id );
			echo '<tr><td class="column-term"><strong>' . esc_html( $term->name ) . '</strong><code>/' . esc_html( $term->slug ) . '/</code></td><td class="column-taxonomy">' . esc_html( $taxonomies[ $term->taxonomy ]->labels->singular_name ?? $term->taxonomy ) . '</td><td class="column-description"><span>' . esc_html( wp_trim_words( wp_strip_all_tags( $term->description ), 18, '…' ) ) . '</span></td>';
			foreach ( Languages::all() as $code => $language ) {
				if ( $code === $default ) { continue; }
				$target_id = absint( $group[ $code ] ?? 0 );
				$target = $target_id ? get_term( $target_id, $term->taxonomy ) : null;
				if ( is_wp_error( $target ) ) { $target = null; $target_id = 0; }
				$seo_fields = array();
				foreach ( SEO::term_translation_fields( $term->term_id, $target_id ) as $provider => $group ) {
					foreach ( $group['fields'] as $field ) { $seo_fields[] = array( 'provider' => $group['name'], 'id' => $field['id'], 'label' => $field['label'], 'source' => $field['source'], 'target' => $field['target'] ); }
				}
				$payload = array( 'sourceId' => $term->term_id, 'targetId' => $target_id, 'taxonomy' => $term->taxonomy, 'language' => $code, 'languageName' => $language['name'], 'flag' => $language['flag'] ?? '🌐', 'sourceName' => $term->name, 'name' => $target ? $target->name : $term->name, 'slug' => $target ? $target->slug : $term->slug, 'description' => $target ? $target->description : $term->description, 'seoFields' => $seo_fields );
				/* translators: %s: language name. */
				$edit_label = sprintf( __( 'Edit %s translation', 'openlingua' ), $language['name'] );
				/* translators: %s: language name. */
				$add_label = sprintf( __( 'Add %s translation', 'openlingua' ), $language['name'] );
				$label = $target ? $edit_label : $add_label;
				echo '<td class="column-language"><button type="button" class="openlingua-taxonomy-action" data-openlingua-taxonomy-edit data-term="' . esc_attr( wp_json_encode( $payload ) ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"><span class="dashicons ' . ( $target ? 'dashicons-edit' : 'dashicons-plus-alt2' ) . '" aria-hidden="true"></span></button></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( array_filter( array( 'page' => 'openlingua-taxonomies', 'taxonomy' => $taxonomy, 's' => $search, 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $paged, 'total' => $total_pages ) ) ) . '</div></div>'; }
		self::modal( $return_to );
		echo '</div>';
	}

	private static function modal( $return_to ) {
		echo '<div class="openlingua-taxonomy-modal" data-openlingua-taxonomy-modal hidden><div class="openlingua-taxonomy-modal__backdrop" data-openlingua-taxonomy-close></div><section class="openlingua-taxonomy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="openlingua-taxonomy-modal-title"><header><div><small>' . esc_html__( 'Taxonomy translation', 'openlingua' ) . '</small><h2 id="openlingua-taxonomy-modal-title" data-openlingua-taxonomy-title></h2></div><button type="button" class="button-link" data-openlingua-taxonomy-close aria-label="' . esc_attr__( 'Close', 'openlingua' ) . '"><span class="dashicons dashicons-no-alt"></span></button></header><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_term_translation"><input type="hidden" name="source_id"><input type="hidden" name="target_id"><input type="hidden" name="taxonomy"><input type="hidden" name="language"><input type="hidden" name="return_to" value="' . esc_attr( $return_to ) . '">';
		wp_nonce_field( 'openlingua_save_term_translation', 'openlingua_taxonomy_nonce' );
		echo '<div class="openlingua-taxonomy-modal__body"><p class="openlingua-taxonomy-modal__source"><span>' . esc_html__( 'Original', 'openlingua' ) . '</span><strong data-openlingua-taxonomy-source></strong></p><label>' . esc_html__( 'Name', 'openlingua' ) . '<input type="text" name="name" required></label><label>' . esc_html__( 'URL slug', 'openlingua' ) . '<input type="text" name="slug"><small data-openlingua-taxonomy-url></small></label><label>' . esc_html__( 'Description', 'openlingua' ) . '<textarea name="description" rows="6"></textarea></label><div class="openlingua-taxonomy-modal__seo" data-openlingua-taxonomy-seo hidden><h3>' . esc_html__( 'SEO metadata', 'openlingua' ) . '</h3><div data-openlingua-taxonomy-seo-fields></div></div></div><footer><button type="button" class="button" data-openlingua-taxonomy-close>' . esc_html__( 'Cancel', 'openlingua' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Save translation', 'openlingua' ) . '</button></footer></form></section></div>';
	}

	public static function save_translation() {
		if ( ! isset( $_POST['openlingua_taxonomy_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['openlingua_taxonomy_nonce'] ) ), 'openlingua_save_term_translation' ) ) { wp_die( esc_html__( 'Invalid request.', 'openlingua' ) ); }
		$source_id = absint( $_POST['source_id'] ?? 0 );
		$target_id = absint( $_POST['target_id'] ?? 0 );
		$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$language = sanitize_key( wp_unslash( $_POST['language'] ?? '' ) );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$tax = get_taxonomy( $taxonomy );
		$source = get_term( $source_id, $taxonomy );
		if ( ! $tax || ! $source || is_wp_error( $source ) || ! Languages::is_valid( $language ) || ! current_user_can( $tax->cap->manage_terms ) || '' === $name ) { wp_die( esc_html__( 'You cannot save this taxonomy translation.', 'openlingua' ) ); }
		$row = Translations::row( 'term', $source_id );
		$group = $row ? $row->group_uuid : Translations::assign( 'term', $source_id, Languages::default_code() );
		$existing = Translations::translated_id( 'term', $source_id, $language );
		if ( $existing ) { $target_id = absint( $existing ); }
		$public_slug = $slug ?: self::public_slug( $source );
		$slug = self::unique_translation_slug( $public_slug, $taxonomy, $language, $target_id );
		$args = array( 'description' => $description, 'slug' => $slug );
		$parent = $source->parent ? self::ensure_translation( $source->parent, $language ) : 0;
		if ( is_wp_error( $parent ) ) { wp_die( esc_html( $parent->get_error_message() ) ); }
		$args['parent'] = $parent;
		if ( $target_id ) {
			$result = wp_update_term( $target_id, $taxonomy, array_merge( $args, array( 'name' => $name ) ) );
		} else {
			$result = wp_insert_term( $name, $taxonomy, $args );
			if ( ! is_wp_error( $result ) ) {
				$target_id = absint( $result['term_id'] );
				foreach ( get_term_meta( $source_id ) as $key => $values ) { foreach ( $values as $value ) { add_term_meta( $target_id, $key, maybe_unserialize( $value ) ); } }
			}
		}
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
		Translations::assign( 'term', $target_id, $language, $group, $row ? $row->language : Languages::default_code() );
		if ( $public_slug ) { update_term_meta( $target_id, self::PUBLIC_SLUG_META, $public_slug ); }
		$seo_translation = isset( $_POST['seo_translation'] ) && is_array( $_POST['seo_translation'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['seo_translation'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array values are sanitized immediately after nonce verification.
		SEO::save_term_translation_fields( $source_id, $target_id, $seo_translation );
		self::synchronize_term_translation_relationships( $source_id, $target_id, $language );
		$return_to = isset( $_POST['return_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_to'] ) ), '' ) : '';
		wp_safe_redirect( add_query_arg( 'updated', '1', $return_to ?: admin_url( 'admin.php?page=openlingua-taxonomies' ) ) ); exit;
	}

	/** Synchronizes existing translated posts after a term translation is created or edited. */
	private static function synchronize_term_translation_relationships( $source_term_id, $target_term_id, $language ) {
		$source_term_id = absint( $source_term_id );
		$target_term_id = absint( $target_term_id );
		$language       = sanitize_key( $language );
		$source         = $source_term_id ? get_term( $source_term_id ) : null;
		$target         = $target_term_id ? get_term( $target_term_id ) : null;
		if ( ! $source || ! $target || is_wp_error( $source ) || is_wp_error( $target ) || $source->taxonomy !== $target->taxonomy || ! Languages::is_valid( $language ) ) { return; }

		global $wpdb;
		$term_taxonomy_id = absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT term_taxonomy_id FROM %i WHERE term_id = %d AND taxonomy = %s LIMIT 1",
			$wpdb->term_taxonomy,
			$source_term_id,
			$source->taxonomy
		) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Native term relationship synchronization after editing a translation.
		if ( ! $term_taxonomy_id ) { return; }
		$source_post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT object_id FROM %i WHERE term_taxonomy_id = %d",
			$wpdb->term_relationships,
			$term_taxonomy_id
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Native term relationship synchronization after editing a translation.
		foreach ( array_filter( array_map( 'absint', (array) $source_post_ids ) ) as $source_post_id ) {
			$target_post_id = Translations::translated_id( 'post', $source_post_id, $language );
			if ( $target_post_id ) { self::synchronize_post_terms( $source_post_id, $target_post_id, $language ); }
		}
	}

	public static function register_fields() {
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'names' ) as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( __CLASS__, 'add_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'edit_fields' ), 10, 2 );
			add_filter( 'manage_edit-' . $taxonomy . '_columns', array( __CLASS__, 'translation_column' ) );
			add_filter( 'manage_' . $taxonomy . '_custom_column', array( __CLASS__, 'translation_column_value' ), 10, 3 );
		}
	}

	public static function add_fields( $taxonomy ) {
		$admin_language = Admin::content_language();
		$language = isset( $_GET['openlingua_language'] ) ? sanitize_key( wp_unslash( $_GET['openlingua_language'] ) ) : ( Languages::is_valid( $admin_language ) ? $admin_language : Languages::default_code() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source   = isset( $_GET['openlingua_source_term'] ) ? absint( $_GET['openlingua_source_term'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_nonce_field( 'openlingua_save_term', 'openlingua_term_nonce' );
		echo '<div class="form-field"><label for="openlingua-term-language">' . esc_html__( 'Language', 'openlingua' ) . '</label>';
		self::select( $language );
		if ( $source ) { echo '<input type="hidden" name="openlingua_source_term" value="' . absint( $source ) . '">'; }
		echo '</div>';
	}

	public static function edit_fields( $term, $taxonomy ) {
		$row     = Translations::row( 'term', $term->term_id );
		$current = $row ? $row->language : Languages::default_code();
		$group   = Translations::group( 'term', $term->term_id );
		$original_id = $current === Languages::default_code() ? 0 : absint( $group[ Languages::default_code() ] ?? 0 );
		wp_nonce_field( 'openlingua_save_term', 'openlingua_term_nonce' );
		echo '<tr class="form-field"><th><label for="openlingua-term-language">' . esc_html__( 'Language', 'openlingua' ) . '</label></th><td>';
		self::select( $current );
		if ( $current !== Languages::default_code() ) {
			$originals = self::available_original_terms( $taxonomy, $current, $term->term_id );
			echo '<p><label for="openlingua-source-term"><strong>' . esc_html__( 'Original term', 'openlingua' ) . '</strong></label><br><select id="openlingua-source-term" name="openlingua_source_term"><option value="0">' . esc_html__( 'Not linked yet', 'openlingua' ) . '</option>';
			// available_original_terms() correctly omits originals that already have
			// this translation. Add the current relation back so the selector reflects
			// an existing link instead of misleadingly showing "Not linked yet".
			$linked_original = $original_id ? get_term( $original_id, $taxonomy ) : null;
			if ( $linked_original && ! is_wp_error( $linked_original ) ) {
				echo '<option value="' . absint( $linked_original->term_id ) . '" selected="selected">' . esc_html( $linked_original->name . ' (/' . $linked_original->slug . '/)' ) . '</option>';
			}
			foreach ( $originals as $original ) {
				if ( $linked_original && absint( $linked_original->term_id ) === absint( $original->term_id ) ) { continue; }
				echo '<option value="' . absint( $original->term_id ) . '" ' . selected( $original_id, $original->term_id, false ) . '>' . esc_html( $original->name . ' (/' . $original->slug . '/)' ) . '</option>';
			}
			echo '</select></p><p class="description">' . esc_html__( 'Use this only to link an existing term that already means the same thing in another language. OpenLingua never guesses this relationship from a name or slug.', 'openlingua' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Translations of this term:', 'openlingua' ) . '</p><ul>';
		foreach ( Languages::all() as $code => $language ) {
			if ( $code === $current ) { continue; }
			if ( isset( $group[ $code ] ) ) {
				$url = get_edit_term_link( $group[ $code ], $taxonomy );
				echo '<li>' . esc_html( $language['name'] ) . ': <a href="' . esc_url( $url ) . '">' . esc_html__( 'Edit', 'openlingua' ) . '</a></li>';
			} else {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'openlingua_duplicate_term', 'term_id' => $term->term_id, 'taxonomy' => $taxonomy, 'language' => $code ), admin_url( 'admin-post.php' ) ), 'openlingua_duplicate_term_' . $term->term_id );
				echo '<li>' . esc_html( $language['name'] ) . ': <a href="' . esc_url( $url ) . '">+ ' . esc_html__( 'Create', 'openlingua' ) . '</a></li>';
			}
		}
		echo '</ul></td></tr>';
	}

	/** Returns source-language terms that do not already have a target in $language. */
	private static function available_original_terms( $taxonomy, $language, $exclude_term_id ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'suppress_filter' => true ) );
		if ( is_wp_error( $terms ) ) { return array(); }
		$default = Languages::default_code();
		return array_values( array_filter( $terms, static function ( $candidate ) use ( $language, $exclude_term_id, $default ) {
			if ( absint( $candidate->term_id ) === absint( $exclude_term_id ) ) { return false; }
			$row = Translations::row( 'term', $candidate->term_id );
			if ( $row && $row->language !== $default ) { return false; }
			return ! Translations::translated_id( 'term', $candidate->term_id, $language );
		} ) );
	}

	private static function select( $current ) {
		echo '<select id="openlingua-term-language" name="openlingua_term_language">';
		foreach ( Languages::all() as $code => $language ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $current, $code, false ) . '>' . esc_html( $language['name'] ) . '</option>';
		}
		echo '</select>';
	}

	public static function translation_column( $columns ) {
		if ( count( Languages::all() ) < 2 ) { return $columns; }
		return $columns + Content::translation_columns();
	}

	public static function translation_column_value( $content, $column, $term_id ) {
		$code = Content::column_language( $column );
		if ( ! $code ) { return $content; }
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) { return $content; }
		$row     = Translations::row( 'term', $term_id );
		$current = $row ? $row->language : Languages::default_code();
		$group   = Translations::group( 'term', $term_id );
		if ( $code === $current ) { return $content; }
		$language = Languages::all()[ $code ];
		$name = $language['name'] ?? strtoupper( $code );
		if ( isset( $group[ $code ] ) ) {
			$translation_id = absint( $group[ $code ] );
			$url   = get_edit_term_link( $translation_id, $term->taxonomy );
			/* translators: %s: language name. */
			$label = sprintf( __( 'Edit %s translation', 'openlingua' ), $name );
			$icon  = 'dashicons-edit';
		} else {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'openlingua_duplicate_term', 'term_id' => $term_id, 'taxonomy' => $term->taxonomy, 'language' => $code ), admin_url( 'admin-post.php' ) ), 'openlingua_duplicate_term_' . $term_id );
			/* translators: %s: language name. */
			$label = sprintf( __( 'Add %s translation', 'openlingua' ), $name );
			$icon  = 'dashicons-plus-alt2';
		}
		if ( ! $url ) { return $content; }
		$output = '<span class="openlingua-translation-links"><a class="openlingua-translation-action" href="' . esc_url( $url ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span></a>';
		if ( isset( $translation_id ) ) {
			$view_url = get_term_link( $translation_id, $term->taxonomy );
			/* translators: %s: language name. */
			$view_label = sprintf( __( 'View %s translation', 'openlingua' ), $name );
			if ( ! is_wp_error( $view_url ) ) { $output .= '<a class="openlingua-translation-action" href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $view_label ) . '" aria-label="' . esc_attr( $view_label ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></a>'; }
		}
		return $output . '</span>';
	}

	public static function save( $term_id, $tt_id, $taxonomy ) {
		if ( ! isset( $_POST['openlingua_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['openlingua_term_nonce'] ) ), 'openlingua_save_term' ) ) { return; }
		$language = isset( $_POST['openlingua_term_language'] ) ? sanitize_key( wp_unslash( $_POST['openlingua_term_language'] ) ) : Languages::default_code();
		$source   = isset( $_POST['openlingua_source_term'] ) ? absint( $_POST['openlingua_source_term'] ) : 0;
		$row      = Translations::row( 'term', $term_id );
		$source_row = $source ? Translations::row( 'term', $source ) : null;
		if ( $source && $language !== Languages::default_code() ) {
			$result = self::link_existing_translation( $source, $term_id, $language );
			if ( is_wp_error( $result ) ) { return; }
			return;
		}
		Translations::assign( 'term', $term_id, $language, $source_row ? $source_row->group_uuid : ( $row ? $row->group_uuid : '' ), $source_row ? $source_row->language : ( $row ? $row->source_language : '' ) );
	}

	public static function duplicate() {
		$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$language = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';
		check_admin_referer( 'openlingua_duplicate_term_' . $term_id );
		$tax = get_taxonomy( $taxonomy );
		if ( ! $term_id || ! $tax || ! Languages::is_valid( $language ) || ! current_user_can( $tax->cap->manage_terms ) ) {
			wp_die( esc_html__( 'You cannot create this term translation.', 'openlingua' ) );
		}
		$source = get_term( $term_id, $taxonomy );
		if ( ! $source || is_wp_error( $source ) ) { wp_die( esc_html__( 'Source term not found.', 'openlingua' ) ); }
		$row = Translations::row( 'term', $term_id );
		if ( ! $row ) { $group = Translations::assign( 'term', $term_id, Languages::default_code() ); } else { $group = $row->group_uuid; }
		$existing = Translations::translated_id( 'term', $term_id, $language );
		if ( $existing ) { wp_safe_redirect( get_edit_term_link( $existing, $taxonomy ) ); exit; }
		$new_id = self::ensure_translation( $term_id, $language );
		if ( is_wp_error( $new_id ) ) { wp_die( esc_html( $new_id->get_error_message() ) ); }
		wp_safe_redirect( get_edit_term_link( $new_id, $taxonomy ) ); exit;
	}

	public static function delete( $term_id ) {
		Translations::delete( 'term', $term_id );
	}
}
