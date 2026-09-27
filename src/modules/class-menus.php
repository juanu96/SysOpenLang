<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Languages;
use SysOpenLang\Plugin;

defined( 'ABSPATH' ) || exit;

final class Menus implements Module {
	private static $editor_language = '';

	public static function hooks() {
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'translate_menu' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'localize_front_page_links' ), 20, 2 );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'add_language_switcher' ), 20, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_openlingua_save_menus', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_openlingua_create_menu_translation', array( __CLASS__, 'create_translation' ) );
		add_action( 'load-nav-menus.php', array( __CLASS__, 'set_editor_language' ), 1 );
		add_action( 'admin_head-nav-menus.php', array( __CLASS__, 'add_translation_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'admin_footer-nav-menus.php', array( __CLASS__, 'render_language_selector' ) );
		add_action( 'init', array( __CLASS__, 'register_menu_item_filters' ), 100 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_menu_item_queries' ), 5 );
		add_filter( 'posts_clauses', array( __CLASS__, 'filter_menu_item_clauses' ), 8, 2 );
	}

	public static function set( $location, $language, $menu_id ) {
		if ( ! Languages::is_valid( $language ) ) { return false; }
		$map = get_option( 'openlingua_menu_map', array() );
		$map[ sanitize_key( $location ) ][ sanitize_key( $language ) ] = absint( $menu_id );
		return update_option( 'openlingua_menu_map', $map );
	}

	public static function get( $location, $language = '' ) {
		$map = get_option( 'openlingua_menu_map', array() );
		$language = $language ?: Languages::current();
		$menu_id = absint( $map[ sanitize_key( $location ) ][ sanitize_key( $language ) ] ?? 0 );
		if ( ! $menu_id && Languages::default_code() === sanitize_key( $language ) ) {
			$assigned = get_nav_menu_locations();
			$menu_id  = absint( $assigned[ sanitize_key( $location ) ] ?? 0 );
		}
		return $menu_id;
	}

	public static function translate_menu( $args ) {
		if ( empty( $args['theme_location'] ) ) { return $args; }
		$menu_id = self::get( $args['theme_location'] );
		if ( $menu_id ) { $args['menu'] = $menu_id; }
		return $args;
	}

	/**
	 * WordPress stores one physical static front-page ID. Its translated pages
	 * still have their own normal permalink (for example /es/inicio/), but a
	 * navigation item representing that front page must use the language root
	 * (/es/) so it behaves as "Home" in every language.
	 */
	public static function localize_front_page_links( $items, $args = null ) {
		if ( is_admin() || ! Languages::is_valid( Languages::current() ) ) { return $items; }
		$front_page_id = absint( get_option( 'page_on_front' ) );
		if ( ! $front_page_id ) { return $items; }
		$front_page_ids = \SysOpenLang\Translations::group( 'post', $front_page_id );
		$front_page_ids[] = $front_page_id;
		$front_page_ids = array_unique( array_map( 'absint', $front_page_ids ) );
		$home_url = Languages::url( home_url( '/' ), Languages::current() );

		foreach ( (array) $items as $item ) {
			if ( ! is_object( $item ) || 'post_type' !== ( $item->type ?? '' ) || 'page' !== ( $item->object ?? '' ) ) { continue; }
			if ( in_array( absint( $item->object_id ?? 0 ), $front_page_ids, true ) ) { $item->url = $home_url; }
		}
		return $items;
	}

	public static function add_language_switcher( $items, $args ) {
		$location = sanitize_key( $args->theme_location ?? '' );
		$settings = Language_Settings::get()['switcher'];
		if ( ! $location || ! in_array( $location, (array) ( $settings['menu_locations'] ?? array() ), true ) ) { return $items; }
		$switcher = Plugin::switcher( array( 'context' => 'menu' ) );
		if ( '' === $switcher ) { return $items; }
		return 'first' === ( $settings['menu_position'] ?? 'last' ) ? $switcher . $items : $items . $switcher;
	}

	public static function admin_menu() {
		add_submenu_page( 'openlingua', __( 'Menu translations', 'sysopenlang' ), __( 'Menus', 'sysopenlang' ), 'manage_options', 'openlingua-menus', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$locations = get_registered_nav_menus();
		$menus = wp_get_nav_menus();
		$switcher = Language_Settings::get()['switcher'];
		echo '<div class="wrap"><h1>' . esc_html__( 'Menu translations', 'sysopenlang' ) . '</h1>';
		echo '<p>' . esc_html__( 'Assign or create a separate WordPress menu for each language. Use Edit menu to translate its navigation labels in the native menu editor.', 'sysopenlang' ) . '</p>';
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Menu translations updated.', 'sysopenlang' ) . '</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $locations ) { echo '<div class="notice notice-info"><p>' . esc_html__( 'The active theme has no registered menu locations.', 'sysopenlang' ) . '</p></div></div>'; return; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_menus">';
		wp_nonce_field( 'openlingua_save_menus' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Theme location', 'sysopenlang' ) . '</th>';
		foreach ( Languages::all() as $code => $language ) { echo '<th><span aria-hidden="true">' . esc_html( $language['flag'] ?? '🌐' ) . '</span> ' . esc_html( $language['name'] ) . ( Languages::default_code() === $code ? ' <small>(' . esc_html__( 'default', 'sysopenlang' ) . ')</small>' : '' ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $locations as $location => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th>';
			foreach ( Languages::all() as $code => $language ) {
				$menu_id = self::get( $location, $code );
				echo '<td><select name="menus[' . esc_attr( $location ) . '][' . esc_attr( $code ) . ']"><option value="0">' . esc_html__( 'Not assigned', 'sysopenlang' ) . '</option>';
				foreach ( $menus as $menu ) { echo '<option value="' . absint( $menu->term_id ) . '" ' . selected( $menu_id, $menu->term_id, false ) . '>' . esc_html( $menu->name ) . '</option>'; }
				echo '</select><p>' . self::action_link( $location, $code, $menu_id ) . '</p></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<section class="openlingua-menu-switcher-settings"><h2>' . esc_html__( 'Language switcher in navigation menus', 'sysopenlang' ) . '</h2><p>' . esc_html__( 'Choose the theme menu locations where SysOpenLang should insert the language switcher automatically.', 'sysopenlang' ) . '</p>';
		echo '<div class="openlingua-switcher-config"><div class="openlingua-switcher-config__controls">';
		foreach ( $locations as $location => $label ) {
			echo '<label class="openlingua-menu-location"><input type="checkbox" name="switcher_menu_locations[]" value="' . esc_attr( $location ) . '" ' . checked( in_array( $location, (array) ( $switcher['menu_locations'] ?? array() ), true ), true, false ) . '> <strong>' . esc_html( $label ) . '</strong> <code>' . esc_html( $location ) . '</code></label>';
		}
		echo '<fieldset class="openlingua-switcher-style"><legend>' . esc_html__( 'Switcher style', 'sysopenlang' ) . '</legend><label><input type="radio" name="switcher_style" value="dropdown" ' . checked( ! empty( $switcher['dropdown'] ), true, false ) . '> <strong>' . esc_html__( 'Dropdown', 'sysopenlang' ) . '</strong><small>' . esc_html__( 'Show the current language and open the other languages below it.', 'sysopenlang' ) . '</small></label><label><input type="radio" name="switcher_style" value="list" ' . checked( empty( $switcher['dropdown'] ), true, false ) . '> <strong>' . esc_html__( 'Language list', 'sysopenlang' ) . '</strong><small>' . esc_html__( 'Show all languages as separate menu items.', 'sysopenlang' ) . '</small></label></fieldset>';
		echo '<fieldset class="openlingua-switcher-content"><legend>' . esc_html__( 'What to display', 'sysopenlang' ) . '</legend><label><input type="checkbox" name="switcher_show_flag" value="1" ' . checked( ! empty( $switcher['show_flag'] ), true, false ) . '> ' . esc_html__( 'Flag or symbol', 'sysopenlang' ) . '</label><label><input type="checkbox" name="switcher_show_name" value="1" ' . checked( ! empty( $switcher['show_name'] ), true, false ) . '> ' . esc_html__( 'Language name', 'sysopenlang' ) . '</label><label><input type="checkbox" name="switcher_show_native_name" value="1" ' . checked( ! empty( $switcher['show_native_name'] ), true, false ) . '> ' . esc_html__( 'Native language name', 'sysopenlang' ) . '</label><label><input type="checkbox" name="switcher_show_current" value="1" ' . checked( ! empty( $switcher['show_current'] ), true, false ) . '> ' . esc_html__( 'Include the current language in list style', 'sysopenlang' ) . '</label></fieldset>';
		echo '<label class="openlingua-menu-position"><span>' . esc_html__( 'Switcher position', 'sysopenlang' ) . '</span><select name="switcher_menu_position"><option value="last" ' . selected( $switcher['menu_position'] ?? 'last', 'last', false ) . '>' . esc_html__( 'At the end of the menu', 'sysopenlang' ) . '</option><option value="first" ' . selected( $switcher['menu_position'] ?? 'last', 'first', false ) . '>' . esc_html__( 'At the beginning of the menu', 'sysopenlang' ) . '</option></select></label>';
		echo '</div><div class="openlingua-switcher-preview"><h3>' . esc_html__( 'Preview', 'sysopenlang' ) . '</h3><div class="openlingua-switcher-preview__menu"><span>' . esc_html__( 'Menu item', 'sysopenlang' ) . '</span><div data-openlingua-switcher-preview></div></div><p class="description">' . esc_html__( 'The final typography and colors are inherited from your WordPress theme.', 'sysopenlang' ) . '</p></div></div>';
		echo '<p class="description">' . esc_html__( 'Languages without a published translation of the current content are hidden automatically. Footer placement remains available under SysOpenLang → Language switcher.', 'sysopenlang' ) . '</p></section>';
		submit_button(); echo '</form></div>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		check_admin_referer( 'openlingua_save_menus' );
		$clean = array();
		$submitted_menus = isset( $_POST['menus'] ) ? map_deep( (array) wp_unslash( $_POST['menus'] ), 'absint' ) : array();
		foreach ( $submitted_menus as $location => $languages ) {
			foreach ( (array) $languages as $code => $menu_id ) {
				if ( Languages::is_valid( $code ) ) { $clean[ sanitize_key( $location ) ][ sanitize_key( $code ) ] = absint( $menu_id ); }
			}
		}
		update_option( 'openlingua_menu_map', $clean );
		$settings = Language_Settings::get();
		$allowed_locations = array_keys( get_registered_nav_menus() );
		$settings['switcher']['menu_locations'] = array_values( array_intersect( $allowed_locations, array_map( 'sanitize_key', isset( $_POST['switcher_menu_locations'] ) ? (array) wp_unslash( $_POST['switcher_menu_locations'] ) : array() ) ) );
		$settings['switcher']['menu_position'] = 'first' === sanitize_key( wp_unslash( $_POST['switcher_menu_position'] ?? '' ) ) ? 'first' : 'last';
		$settings['switcher']['dropdown'] = 'dropdown' === sanitize_key( wp_unslash( $_POST['switcher_style'] ?? '' ) );
		$settings['switcher']['show_flag'] = ! empty( $_POST['switcher_show_flag'] );
		$settings['switcher']['show_name'] = ! empty( $_POST['switcher_show_name'] );
		$settings['switcher']['show_native_name'] = ! empty( $_POST['switcher_show_native_name'] );
		$settings['switcher']['show_current'] = ! empty( $_POST['switcher_show_current'] );
		update_option( 'openlingua_language_settings', $settings );
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-menus', 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	private static function action_link( $location, $language, $menu_id ) {
		if ( $menu_id ) {
			return '<a class="button button-small" href="' . esc_url( add_query_arg( array( 'action' => 'edit', 'menu' => $menu_id, 'openlingua_lang' => $language ), admin_url( 'nav-menus.php' ) ) ) . '">' . esc_html__( 'Edit menu', 'sysopenlang' ) . '</a>';
		}
		$source_id = self::get( $location, Languages::default_code() );
		if ( ! $source_id || Languages::default_code() === $language ) {
			return '<span class="description">' . esc_html__( 'Select or create a menu first.', 'sysopenlang' ) . '</span>';
		}
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'openlingua_create_menu_translation', 'location' => $location, 'language' => $language ), admin_url( 'admin-post.php' ) ), 'openlingua_create_menu_translation_' . $location . '_' . $language );
		return '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Create translation', 'sysopenlang' ) . '</a>';
	}

	public static function create_translation() {
		if ( ! current_user_can( 'edit_theme_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		$location = sanitize_key( wp_unslash( $_GET['location'] ?? '' ) );
		$language = sanitize_key( wp_unslash( $_GET['language'] ?? '' ) );
		check_admin_referer( 'openlingua_create_menu_translation_' . $location . '_' . $language );
		if ( ! isset( get_registered_nav_menus()[ $location ] ) || ! Languages::is_valid( $language ) ) { wp_die( esc_html__( 'Invalid menu translation.', 'sysopenlang' ) ); }
		$existing = self::get( $location, $language );
		if ( $existing ) { self::redirect_to_editor( $existing, $language ); }
		$source_id = self::get( $location, Languages::default_code() );
		$source    = $source_id ? wp_get_nav_menu_object( $source_id ) : false;
		if ( ! $source ) { wp_die( esc_html__( 'Assign a menu to the default language before creating its translation.', 'sysopenlang' ) ); }
		$language_data = Languages::all()[ $language ];
		$name = sprintf( '%1$s — %2$s', $source->name, $language_data['name'] );
		$suffix = 2;
		$base_name = $name;
		while ( wp_get_nav_menu_object( $name ) ) { $name = $base_name . ' ' . $suffix++; }
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) { wp_die( esc_html( $menu_id->get_error_message() ) ); }
		self::set( $location, $language, $menu_id );
		self::redirect_to_editor( $menu_id, $language );
	}

	private static function redirect_to_editor( $menu_id, $language ) {
		wp_safe_redirect( add_query_arg( array( 'action' => 'edit', 'menu' => absint( $menu_id ), 'openlingua_lang' => sanitize_key( $language ) ), admin_url( 'nav-menus.php' ) ) ); exit;
	}

	public static function set_editor_language() {
		$menu_id  = absint( $_REQUEST['menu'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = sanitize_key( wp_unslash( $_REQUEST['openlingua_lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$inferred = self::language_for_menu( $menu_id );
		self::$editor_language = Languages::is_valid( $requested ) && ( ! $inferred || $requested === $inferred ) ? $requested : ( $inferred ?: Languages::default_code() );
		if ( get_current_user_id() ) { update_user_meta( get_current_user_id(), '_openlingua_nav_menu_language', self::$editor_language ); }
	}

	private static function language_for_menu( $menu_id ) {
		if ( ! $menu_id ) { return ''; }
		foreach ( get_registered_nav_menus() as $location => $label ) {
			foreach ( Languages::all() as $code => $language ) {
				if ( $menu_id === self::get( $location, $code ) ) { return $code; }
			}
		}
		return '';
	}

	private static function location_for_menu( $menu_id ) {
		if ( ! $menu_id ) { return ''; }
		foreach ( get_registered_nav_menus() as $location => $label ) {
			foreach ( Languages::all() as $code => $language ) {
				if ( $menu_id === self::get( $location, $code ) ) { return $location; }
			}
		}
		return '';
	}

	private static function query_language() {
		if ( self::$editor_language ) { return self::$editor_language; }
		if ( wp_doing_ajax() && 'menu-quick-search' === sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stored = get_current_user_id() ? sanitize_key( get_user_meta( get_current_user_id(), '_openlingua_nav_menu_language', true ) ) : '';
			return Languages::is_valid( $stored ) ? $stored : '';
		}
		return '';
	}

	public static function filter_menu_item_queries( $query ) {
		if ( ! is_admin() ) { return; }
		$language = self::query_language();
		$post_type = $query->get( 'post_type' );
		if ( ! Languages::is_valid( $language ) || 'nav_menu_item' === $post_type || ( is_array( $post_type ) && in_array( 'nav_menu_item', $post_type, true ) ) ) { return; }
		$query->set( 'openlingua_menu_language', $language );
	}

	public static function filter_menu_item_clauses( $clauses, $query ) {
		$language = sanitize_key( $query->get( 'openlingua_menu_language' ) );
		if ( ! Languages::is_valid( $language ) ) { return $clauses; }
		global $wpdb;
		$table = \SysOpenLang\Database::table( 'translations' );
		$translated = $wpdb->prepare( "EXISTS (SELECT 1 FROM %i ol_menu_lang WHERE ol_menu_lang.element_type = 'post' AND ol_menu_lang.element_id = %i.ID AND ol_menu_lang.language = %s)", $table, $wpdb->posts, $language );
		if ( Languages::default_code() === $language ) {
			$unassigned = $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM %i ol_menu_any WHERE ol_menu_any.element_type = 'post' AND ol_menu_any.element_id = %i.ID)", $table, $wpdb->posts );
			$clauses['where'] .= ' AND (' . $unassigned . ' OR ' . $translated . ')';
		} else {
			$clauses['where'] .= ' AND ' . $translated;
		}
		return $clauses;
	}

	public static function register_menu_item_filters() {
		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $post_type ) {
			add_filter( "nav_menu_items_{$post_type}_recent", array( __CLASS__, 'filter_menu_item_results' ) );
			add_filter( "nav_menu_items_{$post_type}", array( __CLASS__, 'filter_menu_item_results' ) );
		}
	}

	public static function filter_menu_item_results( $posts ) {
		$language = self::query_language();
		if ( ! Languages::is_valid( $language ) ) { return $posts; }
		return array_values( array_filter( (array) $posts, function ( $post ) use ( $language ) {
			if ( ! $post instanceof \WP_Post ) { return true; }
			$row = \SysOpenLang\Translations::row( 'post', $post->ID );
			return Languages::default_code() === $language ? ( ! $row || $language === $row->language ) : ( $row && $language === $row->language );
		} ) );
	}

	public static function add_translation_meta_box() {
		add_meta_box( 'openlingua-menu-translations', __( 'SysOpenLang translations', 'sysopenlang' ), array( __CLASS__, 'translation_meta_box' ), 'nav-menus', 'side', 'high' );
	}

	public static function enqueue_editor_assets( $hook ) {
		if ( ! in_array( $hook, array( 'nav-menus.php', 'openlingua_page_openlingua-menus', 'sysopenlang_page_openlingua-menus' ), true ) ) { return; }
		wp_enqueue_style( 'openlingua-admin-menus', plugins_url( 'assets/admin-menus.css', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION );
		if ( in_array( $hook, array( 'openlingua_page_openlingua-menus', 'sysopenlang_page_openlingua-menus' ), true ) ) {
			wp_enqueue_script( 'openlingua-admin-switcher-settings', plugins_url( 'assets/admin-switcher-settings.js', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION, true );
			$languages = array(); foreach ( Languages::all() as $code => $language ) { $languages[] = array( 'code' => $code, 'flag' => $language['flag'] ?? '🌐', 'name' => $language['name'], 'nativeName' => $language['native_name'] ?? $language['name'] ); }
			wp_localize_script( 'openlingua-admin-switcher-settings', 'openLinguaSwitcherPreview', array( 'languages' => $languages, 'current' => 0 ) );
			return;
		}
		wp_enqueue_script( 'openlingua-admin-menus', plugins_url( 'assets/admin-menus.js', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION, true );
	}

	public static function render_language_selector() {
		$menu_id  = absint( $_REQUEST['menu'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$location = self::location_for_menu( $menu_id );
		if ( ! $menu_id || ! $location ) { return; }
		$current = self::language_for_menu( $menu_id ) ?: Languages::default_code();
		echo '<template id="openlingua-menu-language-template"><label class="openlingua-menu-language" for="openlingua-menu-language"><span>' . esc_html__( 'Language', 'sysopenlang' ) . '</span><select id="openlingua-menu-language">';
		foreach ( Languages::all() as $code => $language ) {
			$translated_menu = self::get( $location, $code );
			$label = ( $language['flag'] ?? '🌐' ) . ' ' . $language['name'];
			if ( $translated_menu ) {
				$url = add_query_arg( array( 'action' => 'edit', 'menu' => $translated_menu, 'openlingua_lang' => $code ), admin_url( 'nav-menus.php' ) );
				echo '<option value="' . esc_url( $url ) . '" ' . selected( $current, $code, false ) . '>' . esc_html( $label ) . '</option>';
			} else {
				echo '<option value="" disabled>' . esc_html( $label . ' — ' . __( 'not created', 'sysopenlang' ) ) . '</option>';
			}
		}
		echo '</select></label></template>';
	}

	public static function translation_meta_box() {
		$menu_id = absint( $_REQUEST['menu'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="openlingua-menu-translations"><p>' . esc_html__( 'Edit a separate menu for each language. Navigation labels can be changed normally in the menu items.', 'sysopenlang' ) . '</p>';
		$found = false;
		foreach ( get_registered_nav_menus() as $location => $label ) {
			$ids = array(); foreach ( Languages::all() as $code => $language ) { $ids[ $code ] = self::get( $location, $code ); }
			if ( $menu_id && ! in_array( $menu_id, $ids, true ) ) { continue; }
			$found = true; echo '<p><strong>' . esc_html( $label ) . '</strong></p><ul>';
			foreach ( Languages::all() as $code => $language ) {
				echo '<li><span aria-hidden="true">' . esc_html( $language['flag'] ?? '🌐' ) . '</span> ' . esc_html( $language['name'] ) . ': ' . self::action_link( $location, $code, $ids[ $code ] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
		}
		if ( ! $found ) { echo '<p class="description">' . esc_html__( 'Assign this menu to a language from SysOpenLang → Menus to link its translations.', 'sysopenlang' ) . '</p>'; }
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=openlingua-menus' ) ) . '">' . esc_html__( 'Manage menu languages', 'sysopenlang' ) . '</a></p></div>';
	}
}
