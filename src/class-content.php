<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

final class Content {
	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_language' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_link' ) );
		add_action( 'admin_post_openlingua_duplicate', array( __CLASS__, 'duplicate' ) );
		add_action( 'admin_post_openlingua_trash_translation', array( __CLASS__, 'trash_translation' ) );
		add_action( 'admin_post_openlingua_restore_translation', array( __CLASS__, 'restore_translation' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_admin_posts' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'mark_frontend_query_language' ), 5 );
		add_filter( 'posts_clauses', array( __CLASS__, 'filter_admin_post_clauses' ), 9, 2 );
		add_filter( 'posts_clauses', array( __CLASS__, 'filter_frontend_posts' ), 10, 2 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'allow_slug_across_languages' ), 10, 6 );
		add_filter( 'manage_posts_columns', array( __CLASS__, 'translation_column' ) );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'translation_column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'translation_column_value' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'translation_column_value' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_cpt_list_table_hooks' ), 99 );
	}

	public static function register_cpt_list_table_hooks() {
		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $post_type ) {
			if ( in_array( $post_type, array( 'post', 'page', 'attachment' ), true ) ) { continue; }
			add_filter( 'manage_' . $post_type . '_posts_columns', array( __CLASS__, 'translation_column' ) );
		}
	}

	public static function meta_box() {
		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' === $post_type ) { continue; }
			add_meta_box( 'openlingua-language', __( 'Languages', 'sysopenlang' ), array( __CLASS__, 'render_meta_box' ), $post_type, 'side', 'high' );
		}
	}

	public static function render_meta_box( $post ) {
		$row      = Translations::row( 'post', $post->ID );
		$admin_language = Admin::content_language();
		$current  = $row ? $row->language : ( Languages::is_valid( $admin_language ) ? $admin_language : Languages::default_code() );
		$group    = Translations::group( 'post', $post->ID );
		wp_nonce_field( 'openlingua_save_post', 'openlingua_nonce' );
		echo '<p><label for="openlingua-language">' . esc_html__( 'Content language', 'sysopenlang' ) . '</label></p>';
		echo '<select id="openlingua-language" name="openlingua_language" class="widefat">';
		foreach ( Languages::all() as $code => $language ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $current, $code, false ) . '>' . esc_html( $language['name'] ) . '</option>';
		}
		echo '</select><hr><strong>' . esc_html__( 'Translations', 'sysopenlang' ) . '</strong>';
		foreach ( Languages::all() as $code => $language ) {
			if ( $code === $current ) { continue; }
			echo '<p>' . esc_html( $language['name'] ) . ': ';
			if ( isset( $group[ $code ] ) ) {
				echo '<a href="' . esc_url( Translation_Editor::url( $post->ID, $group[ $code ] ) ) . '">' . esc_html__( 'Edit translation', 'sysopenlang' ) . '</a>';
			} else {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'openlingua_duplicate', 'post_id' => $post->ID, 'language' => $code ), admin_url( 'admin-post.php' ) ), 'openlingua_duplicate_' . $post->ID );
				echo '<a href="' . esc_url( $url ) . '">+ ' . esc_html__( 'Create', 'sysopenlang' ) . '</a>';
			}
			echo '</p>';
		}
		$status = get_post_meta( $post->ID, \SysOpenLang\Modules\Workflow::STATUS_META, true ) ?: ( $row && $row->source_language ? 'draft' : 'complete' );
		echo '<hr><p><label for="openlingua-status"><strong>' . esc_html__( 'Translation status', 'sysopenlang' ) . '</strong></label></p><select id="openlingua-status" name="openlingua_status" class="widefat">';
		foreach ( \SysOpenLang\Modules\Workflow::statuses() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select>';
	}

	public static function save_language( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		if ( ! isset( $_POST['openlingua_nonce'] ) ) { return; }
		$nonce = sanitize_text_field( wp_unslash( $_POST['openlingua_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'openlingua_save_post' ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$language = isset( $_POST['openlingua_language'] ) ? sanitize_key( wp_unslash( $_POST['openlingua_language'] ) ) : Languages::default_code();
		$row      = Translations::row( 'post', $post_id );
		Translations::assign( 'post', $post_id, $language, $row ? $row->group_uuid : '', $row ? $row->source_language : '' );
		if ( isset( $_POST['openlingua_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_POST['openlingua_status'] ) );
			if ( isset( \SysOpenLang\Modules\Workflow::statuses()[ $status ] ) ) { update_post_meta( $post_id, \SysOpenLang\Modules\Workflow::STATUS_META, $status ); }
		}
	}

	public static function duplicate() {
		$post_id  = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$language = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';
		$return_to = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ) : '';
		check_admin_referer( 'openlingua_duplicate_' . $post_id );
		if ( ! $post_id || ! Languages::is_valid( $language ) || ! current_user_can( 'openlingua_translate' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot create this translation.', 'sysopenlang' ) );
		}
		$source = get_post( $post_id );
		if ( ! $source || 'attachment' === $source->post_type ) {
			wp_die( esc_html__( 'Media files are shared or separated by language from the SysOpenLang media settings and are not duplicated as translations.', 'sysopenlang' ) );
		}
		$row    = Translations::row( 'post', $post_id );
		if ( ! $row ) {
			$group = Translations::assign( 'post', $post_id, Languages::default_code() );
		} else {
			$group = $row->group_uuid;
		}
		$existing = Translations::translated_id( 'post', $post_id, $language );
		if ( $existing ) {
			wp_safe_redirect( Translation_Editor::url( $post_id, $existing, $return_to ) ); exit;
		}
		$behavior = \SysOpenLang\Modules\Site_Settings::get();
		$requested_status = $behavior['new_translation_status'];
		$post_type_object = get_post_type_object( $source->post_type );
		$publish_capability = $post_type_object && ! empty( $post_type_object->cap->publish_posts ) ? $post_type_object->cap->publish_posts : 'publish_posts';
		$post_status = 'pending' === $requested_status ? 'pending' : ( 'publish' === $requested_status && current_user_can( $publish_capability ) ? 'publish' : 'draft' );
		$new_post = array(
			'post_type' => $source->post_type, 'post_status' => $post_status, 'post_title' => $source->post_title,
			'post_content' => $source->post_content, 'post_excerpt' => $source->post_excerpt,
			'post_mime_type' => $source->post_mime_type,
			'post_parent' => $source->post_parent ? ( Translations::translated_id( 'post', $source->post_parent, $language ) ?: $source->post_parent ) : 0,
			'menu_order' => $source->menu_order,
		);
		if ( ! empty( $behavior['copy_author'] ) ) { $new_post['post_author'] = $source->post_author; }
		if ( ! empty( $behavior['copy_date'] ) ) { $new_post['post_date'] = $source->post_date; $new_post['post_date_gmt'] = $source->post_date_gmt; }
		if ( 'source' === $behavior['slug_mode'] ) { $new_post['post_name'] = $source->post_name; }
		$new_id = wp_insert_post( $new_post );
		if ( is_wp_error( $new_id ) ) { wp_die( esc_html( $new_id->get_error_message() ) ); }
		\SysOpenLang\Modules\Metadata::copy( $post_id, $new_id, $source->post_type );
		if ( empty( $behavior['copy_thumbnail'] ) ) { delete_post_thumbnail( $new_id ); }
		if ( empty( $behavior['copy_template'] ) ) { delete_post_meta( $new_id, '_wp_page_template' ); }
		if ( 'product' === $source->post_type ) { \SysOpenLang\Modules\Commerce::initialize_translation( $post_id, $new_id ); }
		$term_sync = Taxonomies::synchronize_post_terms( $post_id, $new_id, $language );
		if ( is_wp_error( $term_sync ) ) { wp_die( esc_html( $term_sync->get_error_message() ) ); }
		Translations::assign( 'post', $new_id, $language, $group, $row ? $row->language : Languages::default_code() );
		\SysOpenLang\Modules\Workflow::mark_created( $new_id, $post_id );
		if ( ! empty( $behavior['automatic_on_create'] ) ) {
			$provider = \SysOpenLang\Modules\Providers::active();
			if ( $provider && $provider->is_configured() ) { \SysOpenLang\Modules\Jobs::enqueue( $post_id, $new_id, $language, $provider->id() ); }
		}
		wp_safe_redirect( Translation_Editor::url( $post_id, $new_id, $return_to ) ); exit;
	}

	public static function filter_admin_posts( $query ) {
		global $pagenow;
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow ) { return; }
		if ( 'trash' === $query->get( 'post_status' ) ) { return; }
		$language = Admin::content_language();
		if ( 'all' !== $language && Languages::is_valid( $language ) ) { $query->set( 'openlingua_admin_language', $language ); }
	}

	/**
	 * Marks secondary public-content queries so modules and widgets receive only the current language.
	 *
	 * WordPress only treats the request query as the main query. Builders commonly create their
	 * own WP_Query instances, but their non-public layout/template records must remain unfiltered.
	 */
	public static function mark_frontend_query_language( $query ) {
		if ( $query->is_main_query() || $query->get( 'suppress_filters' ) || Languages::is_valid( $query->get( 'openlingua_language' ) ) ) { return; }
		$is_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		if ( is_admin() && ! $is_ajax ) { return; }
		if ( ! self::is_public_content_query( $query ) ) { return; }
		// A browser may prefetch /es/ while it is still on /en/, retaining the English Referer.
		// Normal frontend requests already have their language resolved from their own URL; only
		// admin-ajax requests need the explicit/referrer fallback below.
		$language = $is_ajax ? self::request_language() : Languages::current();
		if ( Languages::is_valid( $language ) ) { $query->set( 'openlingua_language', $language ); }
	}

	/** Keeps builder templates, reusable layouts, attachments, and other internal records out of the public content filter. */
	private static function is_public_content_query( $query ) {
		$post_types = array_filter( array_map( 'sanitize_key', (array) $query->get( 'post_type' ) ) );
		if ( ! $post_types ) { $post_types = array( 'post' ); }
		if ( in_array( 'attachment', $post_types, true ) || $query->is_attachment() ) { return false; }
		if ( in_array( 'any', $post_types, true ) ) { return $query->is_search(); }
		foreach ( $post_types as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object || empty( $post_type_object->public ) ) { return false; }
		}
		return true;
	}

	/** Resolves the language for AJAX queries, whose request URL is admin-ajax.php. */
	private static function request_language() {
		$candidates = array(
			isset( $_REQUEST['openlingua_language'] ) ? sanitize_key( wp_unslash( $_REQUEST['openlingua_language'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public AJAX language hint, validated below.
			isset( $_REQUEST['lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public AJAX language hint, validated below.
			isset( $_SERVER['HTTP_X_SYSOPENLANG_LANGUAGE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_SYSOPENLANG_LANGUAGE'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public request header, validated below.
		);
		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) { continue; }
			$candidate = sanitize_key( wp_unslash( (string) $candidate ) );
			if ( Languages::is_valid( $candidate ) ) { return $candidate; }
		}

		$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) );
		$query   = (string) wp_parse_url( $referer, PHP_URL_QUERY );
		parse_str( $query, $query_args );
		$candidate = isset( $query_args['lang'] ) && is_scalar( $query_args['lang'] ) ? sanitize_key( (string) $query_args['lang'] ) : '';
		if ( Languages::is_valid( $candidate ) ) { return $candidate; }

		$path  = (string) wp_parse_url( $referer, PHP_URL_PATH );
		$codes = array_map( 'preg_quote', array_keys( Languages::all() ) );
		if ( $path && $codes && preg_match( '#/(?:' . implode( '|', $codes ) . ')(?=/|$)#', $path, $matches ) ) {
			$segments  = array_values( array_filter( explode( '/', trim( $matches[0], '/' ) ) ) );
			$candidate = sanitize_key( end( $segments ) );
			if ( Languages::is_valid( $candidate ) ) { return $candidate; }
		}

		$host = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
		$settings = (array) get_option( 'openlingua_language_settings', array() );
		foreach ( (array) ( $settings['domains'] ?? array() ) as $code => $domain ) {
			if ( $host && $host === strtolower( (string) wp_parse_url( $domain, PHP_URL_HOST ) ) && Languages::is_valid( $code ) ) { return $code; }
		}
		return Languages::current();
	}

	public static function filter_admin_post_clauses( $clauses, $query ) {
		$language = sanitize_key( $query->get( 'openlingua_admin_language' ) );
		if ( ! is_admin() || ! $query->is_main_query() || ! Languages::is_valid( $language ) ) { return $clauses; }
		global $wpdb;
		$table = Database::table( 'translations' );
		$translated = $wpdb->prepare( "EXISTS (SELECT 1 FROM %i ol_admin_lang WHERE ol_admin_lang.element_type = 'post' AND ol_admin_lang.element_id = %i.ID AND ol_admin_lang.language = %s)", $table, $wpdb->posts, $language );
		if ( Languages::default_code() === $language ) {
			$unassigned = $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM %i ol_admin_any WHERE ol_admin_any.element_type = 'post' AND ol_admin_any.element_id = %i.ID)", $table, $wpdb->posts );
			$clauses['where'] .= ' AND (' . $unassigned . ' OR ' . $translated . ')';
		} else {
			$clauses['where'] .= ' AND ' . $translated;
		}
		return $clauses;
	}

	public static function delete_link( $post_id ) { Translations::delete( 'post', $post_id ); }
	public static function query_vars( $vars ) { $vars[] = 'lang'; return $vars; }

	public static function allow_slug_across_languages( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		if ( 'attachment' === $post_type || ! $original_slug ) { return $slug; }
		$row = Translations::row( 'post', $post_id );
		if ( ! $row || ! Languages::is_valid( $row->language ) ) { return $slug; }
		global $wpdb;
		$conflicts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_name = %s AND post_type = %s AND ID != %d AND post_status != 'trash'", $wpdb->posts, $original_slug, $post_type, $post_id ) );
		foreach ( $conflicts as $conflict_id ) {
			$conflict = Translations::row( 'post', $conflict_id );
			$conflict_language = $conflict ? $conflict->language : Languages::default_code();
			if ( $conflict_language === $row->language ) { return $slug; }
		}
		return sanitize_title( $original_slug );
	}

	public static function translation_column( $columns ) {
		if ( count( Languages::all() ) < 2 ) { return $columns; }
		$translation_columns = self::translation_columns();
		$offset = array_search( 'title', array_keys( $columns ), true );
		if ( false === $offset ) { return $columns + $translation_columns; }
		return array_slice( $columns, 0, $offset + 1, true ) + $translation_columns + array_slice( $columns, $offset + 1, null, true );
	}

	public static function translation_column_value( $column, $post_id ) {
		$language = self::column_language( $column );
		if ( ! $language ) { return; }
		self::render_translation_action( $post_id, $language );
	}

	public static function translation_columns() {
		$columns = array();
		$current = self::list_language();
		$is_trash = isset( $_GET['post_status'] ) && 'trash' === sanitize_key( wp_unslash( $_GET['post_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( Languages::all() as $code => $language ) {
			if ( ! $is_trash && $code === $current ) { continue; }
			$name = $language['name'] ?? strtoupper( $code );
			$flag = $language['flag'] ?? strtoupper( $code );
			$columns[ 'openlingua_translation_' . $code ] = '<span class="openlingua-column-language" title="' . esc_attr( $name ) . '"><span aria-hidden="true">' . esc_html( $flag ) . '</span><span class="screen-reader-text">' . esc_html( $name ) . '</span></span>';
		}
		return $columns;
	}

	public static function list_language() {
		return Admin::content_language();
	}

	public static function column_language( $column ) {
		$prefix = 'openlingua_translation_';
		if ( 0 !== strpos( $column, $prefix ) ) { return ''; }
		$language = sanitize_key( substr( $column, strlen( $prefix ) ) );
		return Languages::is_valid( $language ) ? $language : '';
	}

	public static function render_translation_action( $post_id, $code ) {
		$row     = Translations::row( 'post', $post_id );
		$current = $row ? $row->language : Languages::default_code();
		$group   = Translations::group( 'post', $post_id );
		if ( 'trash' === get_post_status( $post_id ) ) {
			if ( $row && $code === $row->language ) {
				$language = Languages::all()[ $code ] ?? array();
				$name = $language['name'] ?? strtoupper( $code );
				echo '<span class="openlingua-trashed-language"><span class="dashicons dashicons-trash" aria-hidden="true"></span>' . esc_html( $name ) . '<span class="screen-reader-text"> ' . esc_html__( 'trashed translation', 'sysopenlang' ) . '</span></span>';
			} elseif ( ! $row && $code === array_key_first( Languages::all() ) ) {
				echo '<span class="openlingua-trashed-language openlingua-trashed-language--unknown">' . esc_html__( 'Language unavailable', 'sysopenlang' ) . '</span>';
			} else {
				echo '&mdash;';
			}
			return;
		}
		if ( $code === $current ) { return; }
		$language = Languages::all()[ $code ];
		$name = $language['name'] ?? strtoupper( $code );
		if ( isset( $group[ $code ] ) && 'trash' === get_post_status( absint( $group[ $code ] ) ) ) {
			$translation_id = absint( $group[ $code ] );
			$url   = self::restore_translation_url( $post_id, $translation_id );
			/* translators: %s: language name. */
			$label = sprintf( __( 'Restore %s translation from Trash', 'sysopenlang' ), $name );
			$icon  = 'dashicons-undo';
		} elseif ( isset( $group[ $code ] ) ) {
			$translation_id = absint( $group[ $code ] );
			$url   = Translation_Editor::url( $post_id, $translation_id );
			/* translators: %s: language name. */
			$label = sprintf( __( 'Edit %s translation', 'sysopenlang' ), $name );
			$icon  = 'dashicons-edit';
		} else {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'openlingua_duplicate', 'post_id' => $post_id, 'language' => $code ), admin_url( 'admin-post.php' ) ), 'openlingua_duplicate_' . $post_id );
			/* translators: %s: language name. */
			$label = sprintf( __( 'Add %s translation', 'sysopenlang' ), $name );
			$icon  = 'dashicons-plus-alt2';
		}
		if ( ! $url ) { return; }
		echo '<span class="openlingua-translation-links"><a class="openlingua-translation-action" href="' . esc_url( $url ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span></a>';
		if ( isset( $translation_id ) && 'trash' !== get_post_status( $translation_id ) ) {
			$view_url = 'publish' === get_post_status( $translation_id ) ? get_permalink( $translation_id ) : get_preview_post_link( $translation_id );
			/* translators: %s: language name. */
			$view_label = sprintf( __( 'View %s translation', 'sysopenlang' ), $name );
			if ( $view_url ) { echo '<a class="openlingua-translation-action" href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $view_label ) . '" aria-label="' . esc_attr( $view_label ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></a>'; }
			if ( current_user_can( 'delete_post', $translation_id ) ) {
				/* translators: %s: language name. */
				$delete_label = sprintf( __( 'Move %s translation to Trash', 'sysopenlang' ), $name );
				/* translators: %s: language name. */
				$confirmation = sprintf( __( 'Move the %s translation to Trash? The original content will not be deleted.', 'sysopenlang' ), $name );
				echo '<a class="openlingua-translation-action openlingua-translation-action--delete" href="' . esc_url( self::delete_translation_url( $post_id, $translation_id ) ) . '" data-openlingua-confirm="' . esc_attr( $confirmation ) . '" title="' . esc_attr( $delete_label ) . '" aria-label="' . esc_attr( $delete_label ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></a>';
			}
		}
		echo '</span>';
	}

	public static function delete_translation_url( $source_id, $translation_id, $redirect_to = '' ) {
		$args = array(
			'action'         => 'openlingua_trash_translation',
			'source_id'      => absint( $source_id ),
			'translation_id' => absint( $translation_id ),
		);
		if ( $redirect_to ) { $args['redirect_to'] = $redirect_to; }
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'openlingua_trash_translation_' . absint( $translation_id ) );
	}

	public static function restore_translation_url( $source_id, $translation_id ) {
		$url = add_query_arg(
			array( 'action' => 'openlingua_restore_translation', 'source_id' => absint( $source_id ), 'translation_id' => absint( $translation_id ) ),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'openlingua_restore_translation_' . absint( $translation_id ) );
	}

	public static function trash_translation() {
		$source_id      = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
		$translation_id = isset( $_GET['translation_id'] ) ? absint( $_GET['translation_id'] ) : 0;
		check_admin_referer( 'openlingua_trash_translation_' . $translation_id );

		$source      = get_post( $source_id );
		$translation = get_post( $translation_id );
		$group       = $source ? Translations::group( 'post', $source_id ) : array();
		if ( ! $source || ! $translation || $source_id === $translation_id || ! in_array( $translation_id, array_map( 'absint', $group ), true ) ) {
			wp_die( esc_html__( 'These posts are not linked translations.', 'sysopenlang' ) );
		}
		if ( ! current_user_can( 'delete_post', $translation_id ) ) {
			wp_die( esc_html__( 'You cannot delete this translation.', 'sysopenlang' ) );
		}
		delete_post_meta( $translation_id, '_et_theme_builder_marked_as_unused' );
		if ( ! wp_trash_post( $translation_id ) ) {
			wp_die( esc_html__( 'The translation could not be moved to Trash.', 'sysopenlang' ) );
		}

		$fallback = add_query_arg( 'post_type', $source->post_type, admin_url( 'edit.php' ) );
		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), $fallback ) : $fallback;
		wp_safe_redirect( add_query_arg( 'openlingua_translation_trashed', '1', $redirect ) );
		exit;
	}

	public static function restore_translation() {
		$source_id      = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
		$translation_id = isset( $_GET['translation_id'] ) ? absint( $_GET['translation_id'] ) : 0;
		check_admin_referer( 'openlingua_restore_translation_' . $translation_id );
		$source = get_post( $source_id );
		$group  = $source ? Translations::group( 'post', $source_id ) : array();
		if ( ! $source || 'trash' !== get_post_status( $translation_id ) || ! in_array( $translation_id, array_map( 'absint', $group ), true ) ) {
			wp_die( esc_html__( 'These posts are not linked translations.', 'sysopenlang' ) );
		}
		if ( ! current_user_can( 'delete_post', $translation_id ) || ! wp_untrash_post( $translation_id ) ) {
			wp_die( esc_html__( 'The translation could not be restored.', 'sysopenlang' ) );
		}
		wp_safe_redirect( add_query_arg( 'openlingua_translation_restored', '1', add_query_arg( 'post_type', $source->post_type, admin_url( 'edit.php' ) ) ) );
		exit;
	}

	public static function filter_frontend_posts( $clauses, $query ) {
		$requested_language = sanitize_key( $query->get( 'openlingua_language' ) );
		$is_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		if ( ( is_admin() && ( ! $is_ajax || ! Languages::is_valid( $requested_language ) ) ) || ( ! $query->is_main_query() && ! Languages::is_valid( $requested_language ) ) || $query->get( 'suppress_filters' ) ) { return $clauses; }
		global $wpdb;
		$table    = Database::table( 'translations' );
		$language = Languages::is_valid( $requested_language ) ? $requested_language : Languages::current();
		$default  = Languages::default_code();
		if ( $language === $default ) {
			$clauses['where'] .= $wpdb->prepare( " AND (NOT EXISTS (SELECT 1 FROM %i ol_any WHERE ol_any.element_type = 'post' AND ol_any.element_id = %i.ID) OR EXISTS (SELECT 1 FROM %i ol_lang WHERE ol_lang.element_type = 'post' AND ol_lang.element_id = %i.ID AND ol_lang.language = %s))", $table, $wpdb->posts, $table, $wpdb->posts, $language );
		} else {
			$clauses['where'] .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM %i ol_lang WHERE ol_lang.element_type = 'post' AND ol_lang.element_id = %i.ID AND ol_lang.language = %s)", $table, $wpdb->posts, $language );
			$clauses = self::include_source_taxonomy_archive_posts( $clauses, $query, $language, $default );
		}
		if ( $language !== $default && $query->get( 'openlingua_source_order' ) && false !== strpos( (string) ( $clauses['orderby'] ?? '' ), $wpdb->posts . '.post_date' ) ) {
			$source_date = $wpdb->prepare(
				"(SELECT ol_source_post.post_date FROM %i ol_order_current INNER JOIN %i ol_order_source ON ol_order_source.element_type = 'post' AND ol_order_source.group_uuid = ol_order_current.group_uuid AND ol_order_source.language = %s INNER JOIN %i ol_source_post ON ol_source_post.ID = ol_order_source.element_id WHERE ol_order_current.element_type = 'post' AND ol_order_current.element_id = %i.ID LIMIT 1)",
				$table,
				$table,
				$default,
				$wpdb->posts,
				$wpdb->posts
			);
			$clauses['orderby'] = str_replace( $wpdb->posts . '.post_date', 'COALESCE(' . $source_date . ', ' . $wpdb->posts . '.post_date)', $clauses['orderby'] );
		}
		return $clauses;
	}

	/** Lets translated taxonomy archives inherit matching source-language post relationships. */
	private static function include_source_taxonomy_archive_posts( $clauses, $query, $language, $default ) {
		if ( ! $query->is_main_query() || ( function_exists( 'is_tax' ) && ! is_tax() && ! is_category() && ! is_tag() ) ) { return $clauses; }
		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! $term || empty( $term->term_id ) || empty( $term->term_taxonomy_id ) ) { return $clauses; }
		$row = Translations::row( 'term', $term->term_id );
		if ( ! $row || $row->language !== $language ) { return $clauses; }
		$source_term_id = Translations::translated_id( 'term', $term->term_id, $default );
		if ( ! $source_term_id || $source_term_id === (int) $term->term_id ) { return $clauses; }

		global $wpdb;
		$source_tt_id = absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT term_taxonomy_id FROM %i WHERE term_id = %d AND taxonomy = %s LIMIT 1",
			$wpdb->term_taxonomy,
			$source_term_id,
			$term->taxonomy
		) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Completes translated taxonomy archive queries when legacy relationship rows are missing.
		$target_tt_id = absint( $term->term_taxonomy_id );
		if ( ! $source_tt_id || ! $target_tt_id || empty( $clauses['where'] ) ) { return $clauses; }

		$source_match = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM %i ol_current INNER JOIN %i ol_source ON ol_source.element_type = 'post' AND ol_source.group_uuid = ol_current.group_uuid AND ol_source.language = %s INNER JOIN %i ol_source_rel ON ol_source_rel.object_id = ol_source.element_id WHERE ol_current.element_type = 'post' AND ol_current.element_id = %i.ID AND ol_current.language = %s AND ol_source_rel.term_taxonomy_id = %d)",
			Database::table( 'translations' ),
			Database::table( 'translations' ),
			$default,
			$wpdb->term_relationships,
			$wpdb->posts,
			$language,
			$source_tt_id
		);
		$target_pattern = '/([A-Za-z0-9_]+)\.term_taxonomy_id\s+IN\s+\(([^)]*\b' . preg_quote( (string) $target_tt_id, '/' ) . '\b[^)]*)\)/';
		$updated = preg_replace( $target_pattern, '($0 OR ' . $source_match . ')', $clauses['where'], 1 );
		if ( is_string( $updated ) && $updated !== $clauses['where'] ) { $clauses['where'] = $updated; }
		return $clauses;
	}
}
