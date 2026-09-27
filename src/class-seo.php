<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

final class SEO {
	private static function meta_adapters() {
		return array(
			'yoast' => array(
				'name' => 'Yoast SEO',
				'fields' => array(
					'_yoast_wpseo_title' => __( 'SEO title', 'sysopenlang' ), '_yoast_wpseo_metadesc' => __( 'Meta description', 'sysopenlang' ),
					'_yoast_wpseo_focuskw' => __( 'Focus keyphrase', 'sysopenlang' ), '_yoast_wpseo_opengraph-title' => __( 'Facebook title', 'sysopenlang' ),
					'_yoast_wpseo_opengraph-description' => __( 'Facebook description', 'sysopenlang' ), '_yoast_wpseo_twitter-title' => __( 'X title', 'sysopenlang' ),
					'_yoast_wpseo_twitter-description' => __( 'X description', 'sysopenlang' ),
				),
			),
			'rank-math' => array(
				'name' => 'Rank Math',
				'fields' => array(
					'rank_math_title' => __( 'SEO title', 'sysopenlang' ), 'rank_math_description' => __( 'Meta description', 'sysopenlang' ),
					'rank_math_focus_keyword' => __( 'Focus keyword', 'sysopenlang' ), 'rank_math_facebook_title' => __( 'Facebook title', 'sysopenlang' ),
					'rank_math_facebook_description' => __( 'Facebook description', 'sysopenlang' ), 'rank_math_twitter_title' => __( 'X title', 'sysopenlang' ),
					'rank_math_twitter_description' => __( 'X description', 'sysopenlang' ),
				),
			),
			'seopress' => array(
				'name' => 'SEOPress',
				'fields' => array(
					'_seopress_titles_title' => __( 'SEO title', 'sysopenlang' ), '_seopress_titles_desc' => __( 'Meta description', 'sysopenlang' ),
					'_seopress_analysis_target_kw' => __( 'Target keywords', 'sysopenlang' ), '_seopress_social_fb_title' => __( 'Facebook title', 'sysopenlang' ),
					'_seopress_social_fb_desc' => __( 'Facebook description', 'sysopenlang' ), '_seopress_social_twitter_title' => __( 'X title', 'sysopenlang' ),
					'_seopress_social_twitter_desc' => __( 'X description', 'sysopenlang' ),
				),
			),
		);
	}

	public static function translation_fields( $source_id, $target_id ) {
		$groups = array();
		foreach ( self::meta_adapters() as $provider => $adapter ) {
			foreach ( $adapter['fields'] as $key => $label ) {
				$source = (string) get_post_meta( $source_id, $key, true );
				$target = (string) get_post_meta( $target_id, $key, true );
				if ( '' === $source && '' === $target ) { continue; }
				$groups[ $provider ]['name'] = $adapter['name'];
				$groups[ $provider ]['fields'][] = self::field( $provider, $key, $label, $source, $target );
			}
		}
		$aioseo = self::aioseo_rows( $source_id, $target_id );
		if ( $aioseo ) { $groups['aioseo'] = array( 'name' => 'All in One SEO', 'fields' => $aioseo ); }
		return (array) apply_filters( 'openlingua_seo_translation_fields', $groups, $source_id, $target_id );
	}

	private static function field( $provider, $key, $label, $source, $target ) {
		return array( 'id' => substr( hash( 'sha256', $provider . '|' . $key ), 0, 24 ), 'key' => $key, 'label' => $label, 'source' => $source, 'target' => $target );
	}

	private static function aioseo_rows( $source_id, $target_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { return array(); }
		$source = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $table, $source_id ), ARRAY_A );
		$target = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $table, $target_id ), ARRAY_A );
		$labels = array( 'title' => __( 'SEO title', 'sysopenlang' ), 'description' => __( 'Meta description', 'sysopenlang' ), 'og_title' => __( 'Facebook title', 'sysopenlang' ), 'og_description' => __( 'Facebook description', 'sysopenlang' ), 'twitter_title' => __( 'X title', 'sysopenlang' ), 'twitter_description' => __( 'X description', 'sysopenlang' ) );
		$fields = array();
		foreach ( $labels as $key => $label ) {
			$source_value = (string) ( $source[ $key ] ?? '' );
			$target_value = (string) ( $target[ $key ] ?? '' );
			if ( '' !== $source_value || '' !== $target_value ) { $fields[] = self::field( 'aioseo', $key, $label, $source_value, $target_value ); }
		}
		return $fields;
	}

	public static function save_translation_fields( $source_id, $target_id, array $submitted ) {
		$groups = self::translation_fields( $source_id, $target_id );
		$touched = array();
		foreach ( $groups as $provider => $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['id'], $submitted ) ) { continue; }
				$value = sanitize_textarea_field( $submitted[ $field['id'] ] );
				if ( 'aioseo' === $provider ) { self::save_aioseo_field( $target_id, $field['key'], $value ); }
				else { update_post_meta( $target_id, $field['key'], $value ); }
				$touched[ $provider ] = true;
			}
		}
		if ( isset( $touched['yoast'] ) && function_exists( 'YoastSEO' ) ) {
			$yoast = YoastSEO();
			if ( isset( $yoast->helpers->indexable ) && method_exists( $yoast->helpers->indexable, 'delete_for_post' ) ) { $yoast->helpers->indexable->delete_for_post( $target_id ); }
		}
		do_action( 'openlingua_saved_seo_translation_fields', $source_id, $target_id, $submitted );
	}

	public static function term_translation_fields( $source_id, $target_id ) {
		$groups = array();
		foreach ( self::meta_adapters() as $provider => $adapter ) {
			foreach ( $adapter['fields'] as $key => $label ) {
				$source = (string) get_term_meta( $source_id, $key, true );
				$target = $target_id ? (string) get_term_meta( $target_id, $key, true ) : '';
				if ( '' === $source && '' === $target ) { continue; }
				$groups[ $provider ]['name'] = $adapter['name'];
				$groups[ $provider ]['fields'][] = self::field( 'term-' . $provider, $key, $label, $source, $target );
			}
		}
		$aioseo = self::aioseo_term_rows( $source_id, $target_id );
		if ( $aioseo ) { $groups['aioseo'] = array( 'name' => 'All in One SEO', 'fields' => $aioseo ); }
		return (array) apply_filters( 'openlingua_seo_term_translation_fields', $groups, $source_id, $target_id );
	}

	public static function save_term_translation_fields( $source_id, $target_id, array $submitted ) {
		foreach ( self::term_translation_fields( $source_id, $target_id ) as $provider => $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['id'], $submitted ) ) { continue; }
				$value = sanitize_textarea_field( $submitted[ $field['id'] ] );
				if ( 'aioseo' === $provider ) { self::save_aioseo_term_field( $target_id, $field['key'], $value ); }
				else { update_term_meta( $target_id, $field['key'], $value ); }
			}
		}
		do_action( 'openlingua_saved_seo_term_translation_fields', $source_id, $target_id, $submitted );
	}

	private static function save_aioseo_field( $post_id, $key, $value ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE post_id = %d', $table, $post_id ) );
		if ( $exists ) { $wpdb->update( $table, array( $key => $value ), array( 'post_id' => $post_id ) ); }
		else { $wpdb->insert( $table, array( 'post_id' => $post_id, $key => $value ) ); }
	}

	private static function aioseo_term_rows( $source_id, $target_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_terms';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { return array(); }
		$source = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d', $table, $source_id ), ARRAY_A );
		$target = $target_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d', $table, $target_id ), ARRAY_A ) : array();
		$labels = array( 'title' => __( 'SEO title', 'sysopenlang' ), 'description' => __( 'Meta description', 'sysopenlang' ), 'og_title' => __( 'Facebook title', 'sysopenlang' ), 'og_description' => __( 'Facebook description', 'sysopenlang' ), 'twitter_title' => __( 'X title', 'sysopenlang' ), 'twitter_description' => __( 'X description', 'sysopenlang' ) );
		$fields = array();
		foreach ( $labels as $key => $label ) {
			$source_value = (string) ( $source[ $key ] ?? '' );
			$target_value = (string) ( $target[ $key ] ?? '' );
			if ( '' !== $source_value || '' !== $target_value ) { $fields[] = self::field( 'aioseo-term', $key, $label, $source_value, $target_value ); }
		}
		return $fields;
	}

	private static function save_aioseo_term_field( $term_id, $key, $value ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_terms';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT term_id FROM %i WHERE term_id = %d', $table, $term_id ) );
		if ( $exists ) { $wpdb->update( $table, array( $key => $value ), array( 'term_id' => $term_id ) ); }
		else { $wpdb->insert( $table, array( 'term_id' => $term_id, $key => $value ) ); }
	}

	public static function hooks() {
		add_filter( 'wp_robots', array( __CLASS__, 'translation_robots' ) );
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 2 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'post_canonical' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical' ) );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'canonical' ) );
		add_filter( 'seopress_titles_canonical', array( __CLASS__, 'canonical' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'core_post_sitemap_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'core_term_sitemap_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_entry', array( __CLASS__, 'core_sitemap_post_entry' ), 10, 3 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'yoast_excluded_posts' ) );
		add_filter( 'wpseo_exclude_from_sitemap_by_term_ids', array( __CLASS__, 'yoast_excluded_terms' ) );
		add_filter( 'wpseo_xml_sitemap_post_url', array( __CLASS__, 'sitemap_post_url' ), 10, 2 );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'rank_math_sitemap_entry' ), 10, 3 );
		add_filter( 'seopress_sitemaps_single_url', array( __CLASS__, 'seopress_post_sitemap_url' ), 10, 2 );
		add_filter( 'seopress_sitemaps_term_single_url', array( __CLASS__, 'seopress_term_sitemap_url' ), 10, 2 );
		add_filter( 'aioseo_sitemap_exclude_posts', array( __CLASS__, 'aioseo_excluded_posts' ), 10, 2 );
		add_filter( 'aioseo_sitemap_exclude_terms', array( __CLASS__, 'aioseo_excluded_terms' ), 10, 2 );
	}

	public static function translation_robots( $robots ) {
		if ( ! is_singular() ) { return $robots; }
		$settings = \SysOpenLang\Modules\Site_Settings::get();
		$status = get_post_meta( get_queried_object_id(), \SysOpenLang\Modules\Workflow::STATUS_META, true );
		if ( ! empty( $settings['noindex_incomplete'] ) && in_array( $status, array( 'draft', 'in-progress', 'outdated' ), true ) ) { $robots['noindex'] = true; unset( $robots['index'] ); }
		return $robots;
	}

	public static function core_post_sitemap_args( $args, $post_type ) {
		unset( $post_type );
		$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), self::hidden_element_ids( 'post' ) ) ) );
		return $args;
	}

	public static function core_term_sitemap_args( $args, $taxonomy ) {
		unset( $taxonomy );
		$args['exclude'] = array_values( array_unique( array_merge( (array) ( $args['exclude'] ?? array() ), self::hidden_element_ids( 'term' ) ) ) );
		return $args;
	}

	public static function core_sitemap_post_entry( $entry, $post, $post_type ) {
		unset( $post_type );
		if ( $post instanceof \WP_Post ) { $entry['loc'] = self::sitemap_post_url( $entry['loc'] ?? '', $post ); }
		return $entry;
	}

	public static function yoast_excluded_posts( $ids ) {
		return array_values( array_unique( array_merge( (array) $ids, self::hidden_element_ids( 'post' ) ) ) );
	}

	public static function yoast_excluded_terms( $ids ) {
		return array_values( array_unique( array_merge( (array) $ids, self::hidden_element_ids( 'term' ) ) ) );
	}

	public static function aioseo_excluded_posts( $ids, $type = '' ) {
		unset( $type );
		return self::yoast_excluded_posts( $ids );
	}

	public static function aioseo_excluded_terms( $ids, $type = '' ) {
		unset( $type );
		return self::yoast_excluded_terms( $ids );
	}

	public static function sitemap_post_url( $url, $post ) {
		$post = get_post( $post );
		if ( ! $post ) { return $url; }
		$row = Translations::row( 'post', $post->ID );
		if ( $row && ! isset( Languages::public_all()[ $row->language ] ) ) { return ''; }
		$permalink = get_permalink( $post );
		return $permalink ?: $url;
	}

	public static function rank_math_sitemap_entry( $entry, $type, $object ) {
		if ( 'post' === $type && $object instanceof \WP_Post ) {
			$url = self::sitemap_post_url( $entry['loc'] ?? '', $object );
			if ( ! $url ) { return false; }
			$entry['loc'] = $url;
		}
		if ( 'term' === $type && $object instanceof \WP_Term && in_array( $object->term_id, self::hidden_element_ids( 'term' ), true ) ) { return false; }
		return $entry;
	}

	public static function seopress_post_sitemap_url( $url, $post = null ) {
		return self::sitemap_post_url( $url, $post );
	}

	public static function seopress_term_sitemap_url( $url, $term = null ) {
		$term_id = $term instanceof \WP_Term ? $term->term_id : absint( $term );
		return $term_id && in_array( $term_id, self::hidden_element_ids( 'term' ), true ) ? '' : $url;
	}

	private static function hidden_element_ids( $element_type ) {
		static $cache = array();
		$element_type = 'term' === $element_type ? 'term' : 'post';
		if ( isset( $cache[ $element_type ] ) ) { return $cache[ $element_type ]; }
		$hidden = array_values( array_diff( array_keys( Languages::all() ), array_keys( Languages::public_all() ) ) );
		if ( ! $hidden ) { return $cache[ $element_type ] = array(); }
		global $wpdb;
		$ids = array();
		foreach ( $hidden as $language ) {
			$query = $wpdb->prepare( 'SELECT element_id FROM %i WHERE element_type = %s AND language = %s', Database::table( 'translations' ), $element_type, $language );
			$ids = array_merge( $ids, (array) $wpdb->get_col( $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared custom-table lookup cached per request.
		}
		return $cache[ $element_type ] = array_map( 'absint', (array) $ids );
	}

	public static function hreflang() {
		$links = self::current_links();
		if ( count( $links ) < 2 ) { return; }
		foreach ( $links as $language => $url ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $language ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
		$default = Languages::default_code();
		if ( isset( $links[ $default ] ) ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $links[ $default ] ) . '" />' . "\n";
		}
	}

	public static function post_canonical( $url, $post ) {
		$row = Translations::row( 'post', $post->ID );
		return $row ? Languages::url( $url, $row->language ) : $url;
	}

	public static function canonical( $url ) {
		$links = self::current_links();
		return isset( $links[ Languages::current() ] ) ? $links[ Languages::current() ] : $url;
	}

	public static function current_links() {
		$links = array();
		$public_languages = \SysOpenLang\Languages::public_all();
		if ( is_singular() ) {
			foreach ( Translations::group( 'post', get_queried_object_id() ) as $language => $post_id ) {
				if ( isset( $public_languages[ $language ] ) && 'publish' === get_post_status( $post_id ) ) { $links[ $language ] = get_permalink( $post_id ); }
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				foreach ( Translations::group( 'term', $term->term_id ) as $language => $term_id ) {
					if ( ! isset( $public_languages[ $language ] ) ) { continue; }
					$translated_term = get_term( absint( $term_id ) );
					if ( ! $translated_term || is_wp_error( $translated_term ) ) { continue; }
					$link = get_term_link( $translated_term, $translated_term->taxonomy );
					if ( ! is_wp_error( $link ) ) { $links[ $language ] = $link; }
				}
			}
		}
		return $links;
	}
}
