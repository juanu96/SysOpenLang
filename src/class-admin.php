<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'remember_content_language' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'language_admin_bar' ), 80 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'post_filter' ) );
		add_action( 'admin_post_openlingua_save_strings', array( __CLASS__, 'save_strings' ) );
		add_action( 'update_option_openlingua_languages', array( __CLASS__, 'schedule_rewrite_flush' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_translation_actions' ) );
	}

	public static function menu() {
		add_menu_page( __( 'SysOpenLang', 'sysopenlang' ), __( 'SysOpenLang', 'sysopenlang' ), 'manage_options', 'openlingua', array( __CLASS__, 'page' ), 'dashicons-translation', 58 );
		add_submenu_page( 'openlingua', __( 'String translations', 'sysopenlang' ), __( 'Strings', 'sysopenlang' ), 'manage_options', 'openlingua-strings', array( __CLASS__, 'strings_page' ) );
	}

	public static function settings() {
		register_setting( 'openlingua', 'openlingua_languages', array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_languages' ) ) );
		register_setting( 'openlingua', 'openlingua_default_language', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ) );
		register_setting( 'openlingua', 'openlingua_string_discovery', array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ) );
	}

	public static function sanitize_languages( $input ) {
		$output = array();
		foreach ( (array) $input as $code => $language ) {
			if ( '__new' === $code ) {
				$code = sanitize_key( $language['code'] ?? '' );
			}
			$code = sanitize_key( $code );
			if ( $code && ! empty( $language['name'] ) ) {
				$output[ $code ] = array( 'name' => sanitize_text_field( $language['name'] ), 'locale' => sanitize_text_field( $language['locale'] ?? $code ) );
			}
		}
		return $output;
	}

	public static function page() {
		if ( current_user_can( 'manage_options' ) ) { \SysOpenLang\Modules\Language_Settings::page(); }
	}

	public static function post_filter() {
		$current = self::content_language();
		echo '<select name="lang"><option value="all" ' . selected( $current, 'all', false ) . '>' . esc_html__( 'All languages', 'sysopenlang' ) . '</option>';
		foreach ( Languages::all() as $code => $language ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $current, $code, false ) . '>' . esc_html( $language['name'] ) . '</option>';
		}
		echo '</select>';
		wp_nonce_field( 'openlingua_admin_language', 'openlingua_admin_language_nonce' );
	}

	public static function content_language() {
		$requested = self::requested_language();
		if ( $requested ) { return $requested; }
		$saved = get_current_user_id() ? sanitize_key( get_user_meta( get_current_user_id(), '_openlingua_admin_content_language', true ) ) : '';
		return 'all' === $saved || Languages::is_valid( $saved ) ? $saved : Languages::default_code();
	}

	public static function remember_content_language() {
		if ( ! get_current_user_id() || ! isset( $_GET['lang'], $_GET['openlingua_admin_language_nonce'] ) ) { return; }
		$nonce = sanitize_text_field( wp_unslash( $_GET['openlingua_admin_language_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'openlingua_admin_language' ) ) { return; }
		$language = sanitize_key( wp_unslash( $_GET['lang'] ) );
		if ( 'all' === $language || Languages::is_valid( $language ) ) {
			update_user_meta( get_current_user_id(), '_openlingua_admin_content_language', $language );
		}
	}

	public static function language_admin_bar( $admin_bar ) {
		if ( ! is_admin() || ! is_user_logged_in() || count( Languages::all() ) < 2 ) { return; }
		$current = self::content_language();
		$languages = Languages::all();
		if ( 'all' === $current ) {
			$title = '🌐 ' . __( 'All languages', 'sysopenlang' );
		} else {
			$language = $languages[ $current ] ?? array( 'name' => strtoupper( $current ), 'flag' => '🌐' );
			$title = ( $language['flag'] ?? '🌐' ) . ' ' . $language['name'];
		}
		$base_url = remove_query_arg( array( 'lang', 'openlingua_language_filter', 'paged' ) );
		$language_nonce = wp_create_nonce( 'openlingua_admin_language' );
		$admin_bar->add_node( array( 'id' => 'openlingua-language', 'title' => esc_html( $title ), 'href' => false, 'meta' => array( 'title' => esc_attr__( 'Current content language', 'sysopenlang' ) ) ) );
		foreach ( $languages as $code => $language ) {
			$admin_bar->add_node( array( 'parent' => 'openlingua-language', 'id' => 'openlingua-language-' . $code, 'title' => esc_html( ( $language['flag'] ?? '🌐' ) . ' ' . $language['name'] ), 'href' => add_query_arg( array( 'lang' => $code, 'openlingua_admin_language_nonce' => $language_nonce ), $base_url ) ) );
		}
		$admin_bar->add_node( array( 'parent' => 'openlingua-language', 'id' => 'openlingua-language-all', 'title' => '🌐 ' . esc_html__( 'All languages', 'sysopenlang' ), 'href' => add_query_arg( array( 'lang' => 'all', 'openlingua_admin_language_nonce' => $language_nonce ), $base_url ) ) );
	}

	private static function requested_language() {
		$value = '';
		if ( isset( $_GET['lang'] ) ) { $value = sanitize_key( wp_unslash( $_GET['lang'] ) ); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		elseif ( isset( $_GET['openlingua_language_filter'] ) ) { $value = sanitize_key( wp_unslash( $_GET['openlingua_language_filter'] ) ); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'all' === $value || Languages::is_valid( $value ) ) { return $value; }
		return '';
	}

	public static function strings_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$table = Database::table( 'strings' );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$domain = isset( $_GET['domain'] ) ? sanitize_key( wp_unslash( $_GET['domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 25;
		$languages = Languages::all();
		$target_code = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! Languages::is_valid( $target_code ) || Languages::default_code() === $target_code ) {
			$targets = array_keys( array_diff_key( $languages, array( Languages::default_code() => true ) ) );
			$target_code = $targets ? reset( $targets ) : '';
		}
		$offset = ( $paged - 1 ) * $per_page;
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		if ( $domain && $search ) {
			$total = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE domain = %s AND (source_text LIKE %s OR domain LIKE %s OR string_key LIKE %s)', $table, $domain, $like, $like, $like ) ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE domain = %s AND (source_text LIKE %s OR domain LIKE %s OR string_key LIKE %s) ORDER BY domain, source_text LIMIT %d OFFSET %d', $table, $domain, $like, $like, $like, $per_page, $offset ) );
		} elseif ( $domain ) {
			$total = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE domain = %s', $table, $domain ) ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE domain = %s ORDER BY domain, source_text LIMIT %d OFFSET %d', $table, $domain, $per_page, $offset ) );
		} elseif ( $search ) {
			$total = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_text LIKE %s OR domain LIKE %s OR string_key LIKE %s', $table, $like, $like, $like ) ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source_text LIKE %s OR domain LIKE %s OR string_key LIKE %s ORDER BY domain, source_text LIMIT %d OFFSET %d', $table, $like, $like, $like, $per_page, $offset ) );
		} else {
			$total = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY domain, source_text LIMIT %d OFFSET %d', $table, $per_page, $offset ) );
		}
		$domains = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT domain FROM %i ORDER BY domain', $table ) );
		$return_args = array_filter( array( 'page' => 'openlingua-strings', 's' => $search, 'domain' => $domain, 'language' => $target_code, 'paged' => $paged ) );
		$return_to = add_query_arg( $return_args, admin_url( 'admin.php' ) );

		echo '<div class="wrap openlingua-strings"><h1>' . esc_html__( 'String translations', 'sysopenlang' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Translate all strings discovered from WordPress themes, plugins and shortcodes. The Shortcodes section provides an additional view grouped by shortcode.', 'sysopenlang' ) . '</p>';
		echo '<form method="get" class="openlingua-strings__filters"><input type="hidden" name="page" value="openlingua-strings"><label><span class="screen-reader-text">' . esc_html__( 'Search strings', 'sysopenlang' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search text, key or domain', 'sysopenlang' ) . '"></label><label><span class="screen-reader-text">' . esc_html__( 'Filter by domain', 'sysopenlang' ) . '</span><select name="domain"><option value="">' . esc_html__( 'All domains', 'sysopenlang' ) . '</option>';
		foreach ( $domains as $item_domain ) { echo '<option value="' . esc_attr( $item_domain ) . '" ' . selected( $domain, $item_domain, false ) . '>' . esc_html( $item_domain ) . '</option>'; }
		echo '</select></label><label><span class="screen-reader-text">' . esc_html__( 'Translation language', 'sysopenlang' ) . '</span><select name="language">';
		foreach ( $languages as $code => $language ) { if ( Languages::default_code() !== $code ) { echo '<option value="' . esc_attr( $code ) . '" ' . selected( $target_code, $code, false ) . '>' . esc_html( ( $language['flag'] ?? '🌐' ) . ' ' . $language['name'] ) . '</option>'; } }
		echo '</select></label>'; submit_button( __( 'Filter', 'sysopenlang' ), 'secondary', '', false );
		if ( $search || $domain ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_filter( array( 'page' => 'openlingua-strings', 'language' => $target_code ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Clear', 'sysopenlang' ) . '</a>'; }
		echo '</form>';
		if ( ! $rows ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No interface strings match these filters. Enable gettext discovery in Settings and visit the relevant pages to discover more.', 'sysopenlang' ) . '</p></div></div>'; return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_strings"><input type="hidden" name="return_to" value="' . esc_attr( $return_to ) . '">';
		wp_nonce_field( 'openlingua_save_strings' );
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th class="column-domain">' . esc_html__( 'Domain', 'sysopenlang' ) . '</th><th class="column-source">' . esc_html__( 'Original', 'sysopenlang' ) . '</th><th class="column-translation">' . esc_html( $languages[ $target_code ]['name'] ?? strtoupper( $target_code ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$translations = json_decode( $row->translations, true ) ?: array();
			echo '<tr><td class="column-domain" data-colname="' . esc_attr__( 'Domain', 'sysopenlang' ) . '"><strong>' . esc_html( $row->domain ) . '</strong><code title="' . esc_attr( $row->string_key ) . '">' . esc_html( $row->string_key ) . '</code></td><td class="column-source" data-colname="' . esc_attr__( 'Original', 'sysopenlang' ) . '"><span>' . nl2br( esc_html( $row->source_text ) ) . '</span></td><td class="column-translation" data-colname="' . esc_attr( $languages[ $target_code ]['name'] ?? strtoupper( $target_code ) ) . '"><label class="screen-reader-text" for="string-' . absint( $row->id ) . '-' . esc_attr( $target_code ) . '">' . esc_html__( 'Translation', 'sysopenlang' ) . '</label><textarea id="string-' . absint( $row->id ) . '-' . esc_attr( $target_code ) . '" name="translations[' . absint( $row->id ) . '][' . esc_attr( $target_code ) . ']" rows="2">' . esc_textarea( $translations[ $target_code ] ?? '' ) . '</textarea></td></tr>';
		}
		echo '</tbody></table><div class="openlingua-strings__footer">'; submit_button( __( 'Save translations', 'sysopenlang' ), 'primary', 'submit', false );
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) { echo '<div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( array_merge( $return_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $paged, 'total' => $total_pages ) ) ) . '</div>'; }
		echo '</div></form></div>';
	}

	public static function save_strings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		check_admin_referer( 'openlingua_save_strings' );
		global $wpdb;
		$submitted_translations = isset( $_POST['translations'] ) ? map_deep( (array) wp_unslash( $_POST['translations'] ), 'sanitize_textarea_field' ) : array();
		foreach ( $submitted_translations as $id => $translations ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT translations FROM %i WHERE id = %d', Database::table( 'strings' ), absint( $id ) ) );
			$clean = $row ? ( json_decode( $row->translations, true ) ?: array() ) : array();
			foreach ( (array) $translations as $code => $text ) {
				if ( Languages::is_valid( $code ) ) {
					$value = sanitize_textarea_field( $text );
					if ( '' === $value ) { unset( $clean[ sanitize_key( $code ) ] ); } else { $clean[ sanitize_key( $code ) ] = $value; }
				}
			}
			$wpdb->update( Database::table( 'strings' ), array( 'translations' => wp_json_encode( $clean ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => absint( $id ) ) );
		}
		$return_to = isset( $_POST['return_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_to'] ) ), '' ) : '';
		wp_safe_redirect( add_query_arg( 'updated', '1', $return_to ?: admin_url( 'admin.php?page=openlingua-strings' ) ) ); exit;
	}

	public static function schedule_rewrite_flush() {
		update_option( 'openlingua_flush_rewrite_rules', 1 );
	}

	public static function enqueue_translation_actions( $hook ) {
		if ( in_array( $hook, array( 'openlingua_page_openlingua-strings', 'sysopenlang_page_openlingua-strings' ), true ) ) {
			wp_enqueue_style( 'openlingua-admin-strings', plugins_url( 'assets/admin-strings.css', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION );
			return;
		}
		if ( ! in_array( $hook, array( 'edit.php', 'edit-tags.php', 'upload.php' ), true ) || count( Languages::all() ) < 2 ) { return; }
		wp_enqueue_style( 'openlingua-admin-translations', plugins_url( 'assets/admin-translations.css', SYSOPENLANG_FILE ), array( 'dashicons' ), SYSOPENLANG_VERSION );
		wp_enqueue_script( 'openlingua-admin-translations', plugins_url( 'assets/admin-translations.js', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION, true );
	}
}
