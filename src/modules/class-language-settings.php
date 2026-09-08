<?php
namespace OpenLingua\Modules;

use OpenLingua\Contracts\Module;
use OpenLingua\Languages;

defined( 'ABSPATH' ) || exit;

final class Language_Settings implements Module {
	public static function hooks() {
		add_action( 'admin_post_openlingua_save_language_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'browser_redirect' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'footer_switcher' ), 100 );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allowed_redirect_hosts' ) );
	}

	public static function defaults() {
		return array(
			'url_mode' => 'directory', 'domains' => array(), 'admin_language' => 'site-default',
			'hidden_languages' => array(), 'browser_redirect' => 'off', 'media_mode' => 'unified',
			'language_order' => array(), 'fallbacks' => array(),
			'switcher' => array( 'show_flag' => true, 'show_name' => true, 'show_native_name' => false, 'show_current' => true, 'dropdown' => false, 'missing' => 'hide', 'footer' => false, 'menu_locations' => array(), 'menu_position' => 'last' ),
		);
	}

	public static function get() {
		return array_replace_recursive( self::defaults(), (array) get_option( 'openlingua_language_settings', array() ) );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_openlingua' !== $hook ) { return; }
		wp_enqueue_style( 'openlingua-language-settings', plugins_url( 'assets/admin-language-settings.css', OPENLINGUA_FILE ), array(), OPENLINGUA_VERSION );
	}

	public static function page() {
		$catalog  = Language_Catalog::merged();
		$enabled  = Languages::all();
		$settings = self::get();
		$default  = Languages::default_code();
		echo '<div class="wrap openlingua-settings"><h1>' . esc_html__( 'OpenLingua language settings', 'openlingua' ) . '</h1><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_language_settings">';
		wp_nonce_field( 'openlingua_save_language_settings' );
		self::section_languages( $catalog, $enabled, $default );
		self::section_fallbacks( $enabled, $settings );
		self::section_urls( $enabled, $settings );
		self::section_switcher( $settings );
		self::section_visibility( $enabled, $settings );
		self::section_media( $settings );
		self::section_advanced( $enabled, $settings );
		submit_button( __( 'Save language settings', 'openlingua' ), 'primary large' );
		echo '</form></div>';
	}

	private static function section_languages( $catalog, $enabled, $default ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Site languages', 'openlingua' ) . '</h2><p>' . esc_html__( 'Enable the languages visitors can use and select the default language.', 'openlingua' ) . '</p><div class="openlingua-language-grid">';
		foreach ( $catalog as $code => $language ) {
			$active = isset( $enabled[ $code ] );
			echo '<label class="openlingua-language-option' . ( $active ? ' is-enabled' : '' ) . '"><input type="checkbox" name="enabled_languages[]" value="' . esc_attr( $code ) . '" ' . checked( $active, true, false ) . ( $code === $default ? ' data-default="1"' : '' ) . '><span class="openlingua-flag">' . esc_html( $language['flag'] ?? '🌐' ) . '</span><span><strong>' . esc_html( $language['name'] ) . '</strong><small>' . esc_html( $language['native_name'] ?? $language['name'] ) . ' · ' . esc_html( $code ) . '</small></span><input type="radio" name="default_language" value="' . esc_attr( $code ) . '" ' . checked( $default, $code, false ) . ' aria-label="' . esc_attr__( 'Default language', 'openlingua' ) . '"></label>';
		}
		echo '</div><details><summary>' . esc_html__( 'Add a custom language', 'openlingua' ) . '</summary><div class="openlingua-custom-grid"><label>' . esc_html__( 'Code', 'openlingua' ) . '<input name="custom[code]" placeholder="es-ni"></label><label>' . esc_html__( 'English name', 'openlingua' ) . '<input name="custom[name]"></label><label>' . esc_html__( 'Native name', 'openlingua' ) . '<input name="custom[native_name]"></label><label>' . esc_html__( 'WordPress locale', 'openlingua' ) . '<input name="custom[locale]" placeholder="es_NI"></label><label>' . esc_html__( 'Flag or symbol', 'openlingua' ) . '<input name="custom[flag]" placeholder="🇳🇮"></label><label>' . esc_html__( 'Text direction', 'openlingua' ) . '<select name="custom[direction]"><option value="ltr">LTR</option><option value="rtl">RTL</option></select></label></div></details></section>';
	}

	private static function section_fallbacks( $enabled, $settings ) {
		$order = array_flip( array_values( (array) ( $settings['language_order'] ?? array_keys( $enabled ) ) ) );
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Language order and fallback', 'openlingua' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lower order numbers appear first. A fallback is used only when translated content or a registered string is unavailable; leave it disabled to keep missing translations hidden.', 'openlingua' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Language', 'openlingua' ) . '</th><th>' . esc_html__( 'Order', 'openlingua' ) . '</th><th>' . esc_html__( 'Fallback language', 'openlingua' ) . '</th></tr></thead><tbody>';
		foreach ( $enabled as $code => $language ) {
			echo '<tr><th><span aria-hidden="true">' . esc_html( $language['flag'] ?? '🌐' ) . '</span> ' . esc_html( $language['name'] ) . '</th><td><input type="number" min="0" step="1" name="language_order[' . esc_attr( $code ) . ']" value="' . absint( $order[ $code ] ?? count( $order ) ) . '" class="small-text"></td><td><select name="fallbacks[' . esc_attr( $code ) . ']"><option value="">' . esc_html__( 'No fallback', 'openlingua' ) . '</option>';
			foreach ( $enabled as $fallback_code => $fallback_language ) {
				if ( $fallback_code === $code ) { continue; }
				echo '<option value="' . esc_attr( $fallback_code ) . '" ' . selected( $settings['fallbacks'][ $code ] ?? '', $fallback_code, false ) . '>' . esc_html( $fallback_language['name'] ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function section_urls( $enabled, $settings ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Language URL format', 'openlingua' ) . '</h2>';
		$options = array( 'directory' => __( 'Directories: example.com/es/page/', 'openlingua' ), 'query' => __( 'Query parameter: example.com/page/?lang=es', 'openlingua' ), 'domain' => __( 'A different domain or subdomain for each language', 'openlingua' ) );
		foreach ( $options as $value => $label ) { echo '<label class="openlingua-radio"><input type="radio" name="url_mode" value="' . esc_attr( $value ) . '" ' . checked( $settings['url_mode'], $value, false ) . '> ' . esc_html( $label ) . '</label>'; }
		echo '<div class="openlingua-domains"><h3>' . esc_html__( 'Language domains', 'openlingua' ) . '</h3><p>' . esc_html__( 'Enter a complete HTTPS base URL. DNS and WordPress must already accept each domain.', 'openlingua' ) . '</p>';
		foreach ( $enabled as $code => $language ) { echo '<label><span>' . esc_html( $language['name'] ) . '</span><input type="url" name="domains[' . esc_attr( $code ) . ']" value="' . esc_attr( $settings['domains'][ $code ] ?? '' ) . '" placeholder="https://' . esc_attr( $code ) . '.example.com"></label>'; }
		echo '</div></section>';
	}

	private static function section_switcher( $settings ) {
		$s = $settings['switcher'];
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Language switcher', 'openlingua' ) . '</h2>';
		foreach ( array( 'show_flag' => __( 'Show flag or symbol', 'openlingua' ), 'show_name' => __( 'Show translated language name', 'openlingua' ), 'show_native_name' => __( 'Show native language name', 'openlingua' ), 'show_current' => __( 'Include the current language', 'openlingua' ), 'dropdown' => __( 'Render as a dropdown', 'openlingua' ), 'footer' => __( 'Automatically show a switcher in the footer', 'openlingua' ) ) as $key => $label ) { echo '<label class="openlingua-check"><input type="checkbox" name="switcher[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
		echo '<p class="description">' . esc_html__( 'On individual content, languages without a published translation are hidden automatically.', 'openlingua' ) . '</p></section>';
	}

	private static function section_visibility( $enabled, $settings ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Language visibility', 'openlingua' ) . '</h2><p>' . esc_html__( 'Hidden languages remain editable by administrators but are removed from public switchers and discovery endpoints.', 'openlingua' ) . '</p>';
		foreach ( $enabled as $code => $language ) { echo '<label class="openlingua-check"><input type="checkbox" name="hidden_languages[]" value="' . esc_attr( $code ) . '" ' . checked( in_array( $code, $settings['hidden_languages'], true ), true, false ) . '> ' . esc_html( $language['name'] ) . '</label>'; }
		echo '</section>';
	}

	private static function section_media( $settings ) {
		$mode = 'separate' === ( $settings['media_mode'] ?? '' ) ? 'separate' : 'unified';
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Media library by language', 'openlingua' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose which WordPress media files are available while editing content. OpenLingua never needs to duplicate an attachment merely to translate a page.', 'openlingua' ) . '</p>';
		echo '<label class="openlingua-radio"><input type="radio" name="media_mode" value="unified" ' . checked( 'unified', $mode, false ) . '> <strong>' . esc_html__( 'Unified media library', 'openlingua' ) . '</strong><br><span class="description">' . esc_html__( 'Recommended. Every language reuses the same images and files. Legacy translated attachment records are hidden without deleting their files.', 'openlingua' ) . '</span></label>';
		echo '<label class="openlingua-radio"><input type="radio" name="media_mode" value="separate" ' . checked( 'separate', $mode, false ) . '> <strong>' . esc_html__( 'Separate media by language', 'openlingua' ) . '</strong><br><span class="description">' . esc_html__( 'Uploads are assigned to the current content language. The Media Library and media selectors only show files available for that language.', 'openlingua' ) . '</span></label>';
		echo '<p class="description">' . esc_html__( 'Changing this option is reversible and does not delete physical files or attachment records.', 'openlingua' ) . '</p></section>';
	}

	private static function section_advanced( $enabled, $settings ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Administration and browser behavior', 'openlingua' ) . '</h2><label class="openlingua-select">' . esc_html__( 'WordPress administration language', 'openlingua' ) . '<select name="admin_language"><option value="site-default">' . esc_html__( 'Use the site default language', 'openlingua' ) . '</option><option value="user">' . esc_html__( 'Use each user profile language', 'openlingua' ) . '</option>';
		foreach ( $enabled as $code => $language ) { echo '<option value="' . esc_attr( $code ) . '" ' . selected( $settings['admin_language'], $code, false ) . '>' . esc_html( $language['name'] ) . '</option>'; }
		echo '</select></label><label class="openlingua-select">' . esc_html__( 'Browser language redirect', 'openlingua' ) . '<select name="browser_redirect"><option value="off" ' . selected( $settings['browser_redirect'], 'off', false ) . '>' . esc_html__( 'Disabled (recommended for SEO)', 'openlingua' ) . '</option><option value="once" ' . selected( $settings['browser_redirect'], 'once', false ) . '>' . esc_html__( 'Redirect only on the first visit', 'openlingua' ) . '</option><option value="always" ' . selected( $settings['browser_redirect'], 'always', false ) . '>' . esc_html__( 'Redirect whenever the requested language differs', 'openlingua' ) . '</option></select></label><label class="openlingua-check"><input type="checkbox" name="string_discovery" value="1" ' . checked( get_option( 'openlingua_string_discovery', false ), true, false ) . '> ' . esc_html__( 'Temporarily discover gettext strings from themes and plugins', 'openlingua' ) . '</label></section>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'openlingua' ) ); }
		check_admin_referer( 'openlingua_save_language_settings' );
		$custom = isset( $_POST['custom'] ) ? map_deep( (array) wp_unslash( $_POST['custom'] ), 'sanitize_text_field' ) : array();
		$custom_code = sanitize_key( wp_unslash( $custom['code'] ?? '' ) );
		$custom_languages = (array) get_option( 'openlingua_custom_languages', array() );
		if ( $custom_code && ! empty( $custom['name'] ) ) {
			$custom_languages[ $custom_code ] = array( 'name' => sanitize_text_field( wp_unslash( $custom['name'] ) ), 'native_name' => sanitize_text_field( wp_unslash( $custom['native_name'] ?? $custom['name'] ) ), 'locale' => sanitize_text_field( wp_unslash( $custom['locale'] ?? $custom_code ) ), 'flag' => sanitize_text_field( wp_unslash( $custom['flag'] ?? '🌐' ) ), 'direction' => 'rtl' === ( $custom['direction'] ?? '' ) ? 'rtl' : 'ltr' );
			update_option( 'openlingua_custom_languages', $custom_languages );
		}
		$catalog = array_replace( Language_Catalog::all(), $custom_languages );
		$enabled_codes = array_map( 'sanitize_key', isset( $_POST['enabled_languages'] ) ? (array) wp_unslash( $_POST['enabled_languages'] ) : array() );
		if ( $custom_code ) { $enabled_codes[] = $custom_code; }
		$default = sanitize_key( wp_unslash( $_POST['default_language'] ?? '' ) );
		if ( ! isset( $catalog[ $default ] ) ) { $default = Languages::default_code(); }
		if ( ! in_array( $default, $enabled_codes, true ) ) { $enabled_codes[] = $default; }
		$enabled = array_intersect_key( $catalog, array_flip( array_unique( $enabled_codes ) ) );
		if ( ! $enabled ) { wp_die( esc_html__( 'At least one language must remain enabled.', 'openlingua' ) ); }
		update_option( 'openlingua_languages', $enabled ); update_option( 'openlingua_default_language', $default );
		$url_mode = sanitize_key( wp_unslash( $_POST['url_mode'] ?? 'directory' ) );
		if ( ! in_array( $url_mode, array( 'directory', 'query', 'domain' ), true ) ) { $url_mode = 'directory'; }
		$submitted_domains = isset( $_POST['domains'] ) ? map_deep( (array) wp_unslash( $_POST['domains'] ), 'sanitize_text_field' ) : array();
		$domains = array(); foreach ( $submitted_domains as $code => $url ) { if ( isset( $enabled[ $code ] ) && $url ) { $domains[ sanitize_key( $code ) ] = untrailingslashit( esc_url_raw( $url ) ); } }
		$admin_language = sanitize_key( wp_unslash( $_POST['admin_language'] ?? 'site-default' ) ); if ( ! in_array( $admin_language, array_merge( array( 'site-default', 'user' ), array_keys( $enabled ) ), true ) ) { $admin_language = 'site-default'; }
		$browser = sanitize_key( wp_unslash( $_POST['browser_redirect'] ?? 'off' ) ); if ( ! in_array( $browser, array( 'off', 'once', 'always' ), true ) ) { $browser = 'off'; }
		$media_mode = sanitize_key( wp_unslash( $_POST['media_mode'] ?? 'unified' ) ); if ( ! in_array( $media_mode, array( 'unified', 'separate' ), true ) ) { $media_mode = 'unified'; }
		$switcher = isset( $_POST['switcher'] ) ? map_deep( (array) wp_unslash( $_POST['switcher'] ), 'sanitize_text_field' ) : array();
		$registered_locations = array_keys( get_registered_nav_menus() );
		$menu_locations = array_values( array_intersect( $registered_locations, array_map( 'sanitize_key', (array) ( $switcher['menu_locations'] ?? array() ) ) ) );
		$clean_switcher = array( 'show_flag' => ! empty( $switcher['show_flag'] ), 'show_name' => ! empty( $switcher['show_name'] ), 'show_native_name' => ! empty( $switcher['show_native_name'] ), 'show_current' => ! empty( $switcher['show_current'] ), 'dropdown' => ! empty( $switcher['dropdown'] ), 'footer' => ! empty( $switcher['footer'] ), 'missing' => 'hide', 'menu_locations' => $menu_locations, 'menu_position' => 'first' === ( $switcher['menu_position'] ?? '' ) ? 'first' : 'last' );
		$hidden = array_values( array_intersect( array_keys( $enabled ), array_map( 'sanitize_key', isset( $_POST['hidden_languages'] ) ? (array) wp_unslash( $_POST['hidden_languages'] ) : array() ) ) );
		$submitted_order = isset( $_POST['language_order'] ) ? map_deep( (array) wp_unslash( $_POST['language_order'] ), 'sanitize_text_field' ) : array();
		$order_values = array();
		foreach ( array_keys( $enabled ) as $code ) { $order_values[ $code ] = absint( $submitted_order[ $code ] ?? count( $order_values ) ); }
		uksort( $order_values, static function ( $left, $right ) use ( $order_values ) { return $order_values[ $left ] === $order_values[ $right ] ? strcmp( $left, $right ) : $order_values[ $left ] <=> $order_values[ $right ]; } );
		$submitted_fallbacks = isset( $_POST['fallbacks'] ) ? map_deep( (array) wp_unslash( $_POST['fallbacks'] ), 'sanitize_key' ) : array();
		$fallbacks = array();
		foreach ( array_keys( $enabled ) as $code ) {
			$fallback = sanitize_key( $submitted_fallbacks[ $code ] ?? '' );
			if ( $fallback && $fallback !== $code && isset( $enabled[ $fallback ] ) ) { $fallbacks[ $code ] = $fallback; }
		}
		update_option( 'openlingua_language_settings', array( 'url_mode' => $url_mode, 'domains' => $domains, 'admin_language' => $admin_language, 'hidden_languages' => $hidden, 'browser_redirect' => $browser, 'media_mode' => $media_mode, 'language_order' => array_keys( $order_values ), 'fallbacks' => $fallbacks, 'switcher' => $clean_switcher ) );
		$discovery = ! empty( $_POST['string_discovery'] );
		update_option( 'openlingua_string_discovery', $discovery );
		if ( $discovery ) { update_option( 'openlingua_string_discovery_until', time() + max( 1, absint( Site_Settings::get()['discovery_minutes'] ) ) * MINUTE_IN_SECONDS, false ); }
		else { delete_option( 'openlingua_string_discovery_until' ); }
		update_option( 'openlingua_flush_rewrite_rules', 1 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua', 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function browser_redirect() {
		$mode = self::get()['browser_redirect'];
		if ( 'off' === $mode || is_admin() || wp_doing_ajax() || wp_is_json_request() || is_preview() || headers_sent() ) { return; }
		if ( 'once' === $mode && ! empty( $_COOKIE['openlingua_browser_redirect'] ) ) { return; }
		$accepted = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) ) );
		foreach ( preg_split( '/\s*,\s*/', $accepted ) as $candidate ) {
			$code = sanitize_key( strtok( $candidate, ';' ) );
			$base = substr( $code, 0, 2 );
			$target = Languages::is_valid( $code ) ? $code : ( Languages::is_valid( $base ) ? $base : '' );
			if ( $target ) {
				setcookie( 'openlingua_browser_redirect', '1', array( 'expires' => time() + MONTH_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
				if ( $target !== Languages::current() ) {
					$request_path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH );
					wp_safe_redirect( Languages::url( home_url( $request_path ?: '/' ), $target ), 302 ); exit;
				}
				break;
			}
		}
	}

	public static function footer_switcher() {
		if ( self::get()['switcher']['footer'] ) { echo do_shortcode( '[openlingua_switcher]' ); }
	}

	public static function allowed_redirect_hosts( $hosts ) {
		foreach ( (array) self::get()['domains'] as $domain ) {
			$host = wp_parse_url( $domain, PHP_URL_HOST );
			if ( $host ) { $hosts[] = $host; }
		}
		return array_values( array_unique( $hosts ) );
	}
}
