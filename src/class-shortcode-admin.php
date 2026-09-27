<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

/** Lists registered shortcodes and edits their discovered strings by language. */
final class Shortcode_Admin {
	const PAGE = 'openlingua-shortcodes';
	const EDITOR_PAGE = 'openlingua-shortcode-editor';
	const PER_PAGE = 20;

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_post_openlingua_save_shortcode_translation', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_submenu_page( 'openlingua', __( 'Shortcode translations', 'sysopenlang' ), __( 'Shortcodes', 'sysopenlang' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		add_submenu_page( null, __( 'Shortcode translation editor', 'sysopenlang' ), __( 'Shortcode translation editor', 'sysopenlang' ), 'manage_options', self::EDITOR_PAGE, array( __CLASS__, 'editor' ) );
	}

	public static function assets( $hook ) {
		if ( 'openlingua_page_' . self::PAGE === $hook ) {
			wp_enqueue_style( 'openlingua-admin-translations', plugins_url( 'assets/admin-translations.css', SYSOPENLANG_FILE ), array( 'dashicons' ), SYSOPENLANG_VERSION );
			wp_enqueue_style( 'openlingua-admin-shortcodes', plugins_url( 'assets/admin-shortcodes.css', SYSOPENLANG_FILE ), array( 'openlingua-admin-translations' ), SYSOPENLANG_VERSION );
		}
		if ( 'admin_page_' . self::EDITOR_PAGE === $hook ) {
			wp_enqueue_style( 'openlingua-translation-editor', plugins_url( 'assets/translation-editor.css', SYSOPENLANG_FILE ), array( 'dashicons' ), SYSOPENLANG_VERSION );
			wp_enqueue_script( 'openlingua-translation-editor', plugins_url( 'assets/translation-editor.js', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION, true );
		}
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['status'] ) && 'available' === sanitize_key( wp_unslash( $_GET['status'] ) ) ? 'available' : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stats = self::detected_stats();
		$registered = self::registered_shortcodes();
		$catalog = self::catalog( $search, $paged, $view, $registered, $stats );
		$return_to = add_query_arg( array_filter( array( 'page' => self::PAGE, 'paged' => $paged, 's' => $search, 'status' => 'all' === $view ? 'all' : '' ) ), admin_url( 'admin.php' ) );

		echo '<div class="wrap openlingua-shortcodes"><h1>' . esc_html__( 'Shortcode translations', 'sysopenlang' ) . '</h1>';
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shortcode translation saved.', 'sysopenlang' ) . '</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<p class="description">' . esc_html__( 'SysOpenLang lists every shortcode registered in WordPress. Translation icons become available after a shortcode has rendered and its visible strings have been detected.', 'sysopenlang' ) . '</p>';
		echo '<ul class="subsubsub"><li><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '"' . ( 'all' === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'All registered', 'sysopenlang' ) . ' <span class="count">(' . absint( count( array_unique( array_merge( $registered, array_keys( $stats ) ) ) ) ) . ')</span></a> | </li><li><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'status' => 'available' ), admin_url( 'admin.php' ) ) ) . '"' . ( 'available' === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Available', 'sysopenlang' ) . ' <span class="count">(' . absint( count( $stats ) ) . ')</span></a></li></ul>';
		echo '<form method="get" class="openlingua-shortcodes__search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="status" value="' . esc_attr( 'available' === $view ? 'available' : '' ) . '"><label class="screen-reader-text" for="openlingua-shortcode-search">' . esc_html__( 'Search shortcodes', 'sysopenlang' ) . '</label><input id="openlingua-shortcode-search" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search shortcode or detected text', 'sysopenlang' ) . '">';
		submit_button( __( 'Search', 'sysopenlang' ), 'secondary', '', false );
		if ( $search ) { echo '<a class="button" href="' . esc_url( add_query_arg( array_filter( array( 'page' => self::PAGE, 'status' => 'available' === $view ? 'available' : '' ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Clear', 'sysopenlang' ) . '</a>'; }
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped table-view-list openlingua-shortcodes__table"><thead><tr><th class="column-primary">' . esc_html__( 'Shortcode', 'sysopenlang' ) . '</th><th class="openlingua-shortcodes__source-column">' . esc_html__( 'Original', 'sysopenlang' ) . '</th>';
		foreach ( self::target_languages() as $code => $language ) { echo '<th class="openlingua-shortcodes__language-column"><span class="openlingua-column-language" title="' . esc_attr( $language['name'] ) . '"><span aria-hidden="true">' . esc_html( $language['flag'] ?? strtoupper( $code ) ) . '</span><span class="screen-reader-text">' . esc_html( $language['name'] ) . '</span></span></th>'; }
		echo '</tr></thead><tbody>';
		if ( ! $catalog['items'] ) {
			echo '<tr class="no-items"><td colspan="' . absint( 2 + count( self::target_languages() ) ) . '">' . esc_html__( 'No shortcodes found.', 'sysopenlang' ) . '</td></tr>';
		}
		foreach ( $catalog['items'] as $item ) { self::catalog_row( $item, $return_to ); }
		echo '</tbody><tfoot><tr><th>' . esc_html__( 'Shortcode', 'sysopenlang' ) . '</th><th>' . esc_html__( 'Original', 'sysopenlang' ) . '</th>';
		foreach ( self::target_languages() as $code => $language ) { echo '<th><span class="openlingua-column-language" title="' . esc_attr( $language['name'] ) . '"><span aria-hidden="true">' . esc_html( $language['flag'] ?? strtoupper( $code ) ) . '</span><span class="screen-reader-text">' . esc_html( $language['name'] ) . '</span></span></th>'; }
		echo '</tr></tfoot></table>';
		self::pagination( $catalog['total'], $paged, $search, $view );
		echo '</div>';
	}

	private static function catalog_row( array $item, $return_to ) {
		$source = Languages::all()[ $item['source_language'] ] ?? array( 'name' => strtoupper( $item['source_language'] ), 'flag' => '🌐' );
		/* translators: %d: number of detected strings. */
		echo '<tr><td class="column-primary"><strong><code>[' . esc_html( $item['name'] ) . ']</code></strong><div class="row-actions"><span>' . ( $item['total'] ? sprintf( esc_html( _n( '%d detected string', '%d detected strings', $item['total'], 'sysopenlang' ) ), absint( $item['total'] ) ) : esc_html__( 'No strings detected yet', 'sysopenlang' ) ) . '</span></div><button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'Show more details', 'sysopenlang' ) . '</span></button></td>';
		echo '<td class="openlingua-shortcodes__source-column"><span class="openlingua-column-language" title="' . esc_attr( $source['name'] ) . '"><span aria-hidden="true">' . esc_html( $source['flag'] ?? strtoupper( $item['source_language'] ) ) . '</span><span class="screen-reader-text">' . esc_html( $source['name'] ) . '</span></span></td>';
		foreach ( self::target_languages() as $code => $language ) {
			echo '<td class="openlingua-shortcodes__language-column" data-colname="' . esc_attr( $language['name'] ) . '">';
			if ( ! $item['total'] ) {
				echo '<span class="openlingua-shortcodes__unavailable" title="' . esc_attr__( 'Render this shortcode on the site to detect its strings.', 'sysopenlang' ) . '">&mdash;</span>';
			} else {
				$complete = absint( $item['complete'][ $code ] ?? 0 );
				$url = self::editor_url( $item['name'], $code, $return_to );
				/* translators: 1: language name, 2: shortcode name. */
				$label = sprintf( __( 'Edit %1$s translation for %2$s', 'sysopenlang' ), $language['name'], '[' . $item['name'] . ']' );
				echo '<a class="openlingua-translation-action openlingua-shortcodes__edit' . ( $complete === $item['total'] ? ' is-complete' : ' is-pending' ) . '" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label . ' — ' . $complete . '/' . $item['total'] ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="openlingua-shortcodes__progress-count">' . absint( $complete ) . '/' . absint( $item['total'] ) . '</span></a>';
			}
			echo '</td>';
		}
		echo '</tr>';
	}

	public static function editor_url( $shortcode, $language, $return_to = '' ) {
		$args = array( 'page' => self::EDITOR_PAGE, 'shortcode' => sanitize_key( $shortcode ), 'language' => sanitize_key( $language ) );
		if ( $return_to ) { $args['return_to'] = $return_to; }
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function editor() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$shortcode = isset( $_GET['shortcode'] ) ? sanitize_key( wp_unslash( $_GET['shortcode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$language = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows = self::domain_rows( $shortcode );
		if ( ! $shortcode || ! Languages::is_valid( $language ) || ! $rows ) { wp_die( esc_html__( 'The shortcode translation could not be loaded.', 'sysopenlang' ) ); }
		$source_code = sanitize_key( $rows[0]->source_language ?: Languages::default_code() );
		if ( $language === $source_code ) { wp_die( esc_html__( 'Choose a language different from the original.', 'sysopenlang' ) ); }
		$languages = Languages::all();
		$source = $languages[ $source_code ] ?? array( 'name' => strtoupper( $source_code ), 'flag' => '🌐' );
		$target = $languages[ $language ];
		$return_to = isset( $_GET['return_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_to'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$back = $return_to ?: admin_url( 'admin.php?page=' . self::PAGE );

		echo '<div class="wrap openlingua-editor openlingua-shortcode-editor"><header class="openlingua-editor__top"><a class="openlingua-editor__back" href="' . esc_url( $back ) . '"><span class="dashicons dashicons-arrow-left-alt"></span>' . esc_html__( 'Back', 'sysopenlang' ) . '</a><div><span>' . esc_html__( 'Translating shortcode', 'sysopenlang' ) . '</span><strong>[' . esc_html( $shortcode ) . ']</strong></div><label class="openlingua-editor__search"><span class="dashicons dashicons-search"></span><input type="search" placeholder="' . esc_attr__( 'Search content', 'sysopenlang' ) . '"></label></header>';
		echo '<div class="openlingua-editor__languages"><div><small>' . esc_html__( 'Original', 'sysopenlang' ) . '</small><strong><span aria-hidden="true">' . esc_html( $source['flag'] ?? '🌐' ) . '</span> ' . esc_html( $source['name'] ) . '</strong></div><div><small>' . esc_html__( 'Translation', 'sysopenlang' ) . '</small><strong><span aria-hidden="true">' . esc_html( $target['flag'] ?? '🌐' ) . '</span> ' . esc_html( $target['name'] ) . '</strong></div></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_shortcode_translation"><input type="hidden" name="shortcode" value="' . esc_attr( $shortcode ) . '"><input type="hidden" name="language" value="' . esc_attr( $language ) . '"><input type="hidden" name="return_to" value="' . esc_attr( $back ) . '">';
		wp_nonce_field( 'openlingua_save_shortcode_translation_' . $shortcode . '_' . $language );
		/* translators: %d: number of detected texts. */
		echo '<main class="openlingua-editor__segments"><h2>' . sprintf( esc_html__( 'Detected texts (%d)', 'sysopenlang' ), count( $rows ) ) . '</h2>';
		foreach ( $rows as $index => $row ) {
			$translations = json_decode( $row->translations, true ) ?: array();
			$value = $translations[ $language ] ?? '';
			/* translators: %d: sequential text number. */
			$text_label = sprintf( __( 'Text %d', 'sysopenlang' ), absint( $index + 1 ) );
			echo '<section class="openlingua-editor__segment" data-openlingua-segment><div class="openlingua-editor__source"><label>' . esc_html( $text_label ) . '</label><div class="openlingua-editor__original">' . nl2br( esc_html( $row->source_text ) ) . '</div></div><div class="openlingua-editor__target"><label for="openlingua-shortcode-string-' . absint( $row->id ) . '">' . esc_html( $text_label ) . '</label><textarea id="openlingua-shortcode-string-' . absint( $row->id ) . '" name="translations[' . absint( $row->id ) . ']" rows="2" data-openlingua-translation>' . esc_textarea( $value ) . '</textarea></div></section>';
		}
		/* translators: %d: number of shortcode texts. */
		echo '</main><footer class="openlingua-editor__footer"><div class="openlingua-editor__footer-actions"><span>' . sprintf( esc_html__( '%d shortcode texts', 'sysopenlang' ), count( $rows ) ) . '</span></div><div class="openlingua-editor__progress"><strong data-openlingua-progress>0%</strong><span><i data-openlingua-progress-bar></i></span></div><div><button class="button button-primary" type="submit">' . esc_html__( 'Save and complete', 'sysopenlang' ) . '</button></div></footer></form></div>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		$shortcode = isset( $_POST['shortcode'] ) ? sanitize_key( wp_unslash( $_POST['shortcode'] ) ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		check_admin_referer( 'openlingua_save_shortcode_translation_' . $shortcode . '_' . $language );
		if ( ! $shortcode || ! Languages::is_valid( $language ) ) { wp_die( esc_html__( 'Invalid shortcode translation.', 'sysopenlang' ) ); }
		global $wpdb;
		$table = Database::table( 'strings' );
		$domain = 'shortcode-' . $shortcode;
		$submitted_translations = isset( $_POST['translations'] ) ? map_deep( (array) wp_unslash( $_POST['translations'] ), 'sanitize_textarea_field' ) : array();
		foreach ( $submitted_translations as $id => $text ) {
			$id = absint( $id );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT translations FROM %i WHERE id = %d AND domain = %s', $table, $id, $domain ) );
			if ( ! $row ) { continue; }
			$translations = json_decode( $row->translations, true ) ?: array();
			$value = sanitize_textarea_field( $text );
			if ( '' === $value ) { unset( $translations[ $language ] ); } else { $translations[ $language ] = $value; }
			$wpdb->update( $table, array( 'translations' => wp_json_encode( $translations ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		}
		$return_to = isset( $_POST['return_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_to'] ) ), '' ) : '';
		$destination = $return_to ?: admin_url( 'admin.php?page=' . self::PAGE );
		wp_safe_redirect( add_query_arg( 'updated', '1', $destination ) ); exit;
	}

	private static function catalog( $search, $paged, $view, array $registered, array $stats ) {
		$names = 'all' === $view ? array_values( array_unique( array_merge( $registered, array_keys( $stats ) ) ) ) : array_keys( $stats );
		if ( $search ) {
			$matching_domains = self::matching_domains( $search );
			$names = array_values( array_filter( $names, static function ( $name ) use ( $search, $matching_domains ) { return false !== stripos( $name, $search ) || in_array( $name, $matching_domains, true ); } ) );
		}
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		$total = count( $names );
		$names = array_slice( $names, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$items = array();
		foreach ( $names as $name ) { $items[] = array_merge( array( 'name' => $name, 'total' => 0, 'source_language' => Languages::default_code(), 'complete' => array() ), $stats[ $name ] ?? array() ); }
		return array( 'items' => $items, 'total' => $total );
	}

	private static function detected_stats() {
		global $wpdb;
		$table = Database::table( 'strings' );
		$prefix = $wpdb->esc_like( 'shortcode-' ) . '%';
		$divi = $wpdb->esc_like( 'shortcode-et_pb_' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT domain, source_language, translations FROM %i WHERE domain LIKE %s AND domain NOT LIKE %s ORDER BY domain', $table, $prefix, $divi ) );
		$stats = array();
		foreach ( $rows as $row ) {
			$name = self::name_from_domain( $row->domain );
			if ( ! isset( $stats[ $name ] ) ) { $stats[ $name ] = array( 'total' => 0, 'source_language' => sanitize_key( $row->source_language ?: Languages::default_code() ), 'complete' => array() ); }
			$stats[ $name ]['total']++;
			$translations = json_decode( $row->translations, true ) ?: array();
			foreach ( $translations as $code => $text ) { if ( Languages::is_valid( $code ) && '' !== trim( $text ) ) { $stats[ $name ]['complete'][ $code ] = ( $stats[ $name ]['complete'][ $code ] ?? 0 ) + 1; } }
		}
		return $stats;
	}

	private static function matching_domains( $search ) {
		global $wpdb;
		$table = Database::table( 'strings' );
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$prefix = $wpdb->esc_like( 'shortcode-' ) . '%';
		$domains = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT domain FROM %i WHERE domain LIKE %s AND source_text LIKE %s', $table, $prefix, $like ) );
		return array_values( array_filter( array_map( array( __CLASS__, 'name_from_domain' ), $domains ) ) );
	}

	private static function domain_rows( $shortcode ) {
		if ( ! $shortcode ) { return array(); }
		global $wpdb;
		$table = Database::table( 'strings' );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE domain = %s ORDER BY source_text, string_key', $table, 'shortcode-' . $shortcode ) );
	}

	private static function target_languages() {
		$languages = Languages::all();
		unset( $languages[ Languages::default_code() ] );
		return $languages;
	}

	private static function registered_shortcodes() {
		global $shortcode_tags;
		$names = is_array( $shortcode_tags ) ? array_map( 'sanitize_key', array_keys( $shortcode_tags ) ) : array();
		$names = array_filter( $names, array( 'SysOpenLang\\Shortcode_Content', 'is_supported' ) );
		$names = array_values( array_filter( array_unique( $names ) ) );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return $names;
	}

	public static function name_from_domain( $domain ) {
		return 0 === strpos( $domain, 'shortcode-' ) ? substr( $domain, strlen( 'shortcode-' ) ) : '';
	}

	private static function pagination( $total, $paged, $search, $view ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) { return; }
		$base = add_query_arg( array_filter( array( 'page' => self::PAGE, 's' => $search, 'status' => 'available' === $view ? 'available' : '', 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
	}
}
