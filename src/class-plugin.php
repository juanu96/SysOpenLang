<?php
namespace OpenLingua;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function boot() {
		Content_Extractors::hooks();
		Database::maybe_upgrade();
		if ( is_multisite() ) { add_action( 'wp_initialize_site', array( 'OpenLingua\\Database', 'install_new_site' ), 20, 1 ); }
		Content::hooks();
		Gutenberg_Content::hooks();
		Global_Content::hooks();
		Divi_Content::hooks();
		Divi_Theme_Builder::hooks();
		Translation_Editor::hooks();
		Taxonomies::hooks();
		Shortcode_Content::hooks();
		Shortcode_Admin::hooks();
		Routing::hooks();
		SEO::hooks();
		REST::hooks();
		GraphQL::hooks();
		Admin::hooks();
		Module_Registry::boot( apply_filters( 'openlingua_modules', array(
			\OpenLingua\Modules\Language_Settings::class,
			\OpenLingua\Modules\Site_Settings::class,
			\OpenLingua\Modules\Workflow::class,
			\OpenLingua\Modules\Menus::class,
			\OpenLingua\Modules\Media::class,
			\OpenLingua\Modules\Metadata::class,
			\OpenLingua\Modules\Commerce::class,
			\OpenLingua\Modules\Providers::class,
			\OpenLingua\Modules\OpenAI_Provider::class,
			\OpenLingua\Modules\Anthropic_Provider::class,
			\OpenLingua\Modules\Gemini_Provider::class,
			\OpenLingua\Modules\Google_Translate_Provider::class,
			\OpenLingua\Modules\Jobs::class,
			\OpenLingua\Modules\String_Discovery::class,
			\OpenLingua\Modules\Portability::class,
			\OpenLingua\Modules\Diagnostics::class,
			\OpenLingua\Modules\Privacy::class,
			\OpenLingua\Modules\CLI::class,
		) ) );
		add_shortcode( 'openlingua_switcher', array( __CLASS__, 'switcher' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'switcher_assets' ) );
		add_filter( 'locale', array( __CLASS__, 'locale' ) );
		add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ) );
	}

	public static function switcher_assets() {
		wp_enqueue_style( 'openlingua-switcher', plugins_url( 'assets/switcher.css', OPENLINGUA_FILE ), array(), OPENLINGUA_VERSION );
		wp_enqueue_script( 'openlingua-switcher', plugins_url( 'assets/switcher.js', OPENLINGUA_FILE ), array(), OPENLINGUA_VERSION, true );
	}

	public static function switcher( $atts = array() ) {
		$atts = shortcode_atts( array( 'context' => 'standalone' ), is_array( $atts ) ? $atts : array(), 'openlingua_switcher' );
		$menu_context = 'menu' === $atts['context'];
		$context    = self::switcher_content_context();
		$settings   = \OpenLingua\Modules\Language_Settings::get()['switcher'];
		$items      = '';
		foreach ( Languages::public_all() as $code => $language ) {
			$is_current = Languages::current() === $code;
			if ( $is_current && ( empty( $settings['show_current'] ) || ! empty( $settings['dropdown'] ) ) ) { continue; }
			$url = self::switcher_language_url( $context, $code );
			if ( '' === $url ) { continue; }
			$label = self::switcher_language_label( $code, $language, $settings );
			$items .= '<li class="openlingua-switcher__item menu-item"><a hreflang="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '" href="' . esc_url( Languages::url( $url, $code ) ) . '"' . ( Languages::current() === $code ? ' aria-current="page"' : '' ) . '>' . $label . '</a></li>';
		}
		if ( '' === $items ) {
			$current_code = Languages::current();
			$current = Languages::all()[ $current_code ] ?? array( 'name' => strtoupper( $current_code ) );
			$indicator = '<span class="openlingua-switcher openlingua-switcher--single" aria-label="' . esc_attr__( 'Current language', 'openlingua' ) . '">' . self::switcher_language_label( $current_code, $current, $settings ) . '</span>';
			return $menu_context ? '<li class="openlingua-switcher-menu-item menu-item">' . $indicator . '</li>' : '<nav aria-label="' . esc_attr__( 'Languages', 'openlingua' ) . '">' . $indicator . '</nav>';
		}
		if ( ! empty( $settings['dropdown'] ) ) {
			$current_code = Languages::current();
			$current = Languages::all()[ $current_code ] ?? array( 'name' => strtoupper( $current_code ) );
			$dropdown = '<details class="openlingua-switcher openlingua-switcher--dropdown"><summary>' . self::switcher_language_label( $current_code, $current, $settings ) . '</summary><ul>' . $items . '</ul></details>';
			return $menu_context ? '<li class="openlingua-switcher-menu-item menu-item">' . $dropdown . '</li>' : '<nav aria-label="' . esc_attr__( 'Languages', 'openlingua' ) . '">' . $dropdown . '</nav>';
		}
		if ( $menu_context ) { return $items; }
		return '<nav class="openlingua-switcher" aria-label="' . esc_attr__( 'Languages', 'openlingua' ) . '"><ul>' . $items . '</ul></nav>';
	}

	private static function switcher_content_context() {
		$element_id = get_queried_object_id();
		if ( ! $element_id ) { return array(); }
		if ( is_singular() ) { return array( 'type' => 'post', 'id' => $element_id, 'group' => Translations::group( 'post', $element_id ) ); }
		if ( is_category() || is_tag() || is_tax() ) { return array( 'type' => 'term', 'id' => $element_id, 'group' => Translations::group( 'term', $element_id ) ); }
		return array();
	}

	private static function switcher_language_url( array $context, $language ) {
		if ( empty( $context['type'] ) ) { return home_url( '/' ); }
		$target_id = Languages::current() === $language ? absint( $context['id'] ?? 0 ) : absint( $context['group'][ $language ] ?? 0 );
		if ( ! $target_id ) {
			$behavior = class_exists( '\OpenLingua\Modules\Site_Settings' ) ? \OpenLingua\Modules\Site_Settings::get() : array( 'missing_translation' => 'hide' );
			if ( 'home' === $behavior['missing_translation'] ) { return home_url( '/' ); }
			if ( 'fallback' === $behavior['missing_translation'] ) {
				foreach ( Languages::fallback_chain( $language ) as $fallback ) { if ( ! empty( $context['group'][ $fallback ] ) ) { $target_id = absint( $context['group'][ $fallback ] ); break; } }
			}
			if ( ! $target_id ) { return ''; }
		}
		if ( 'post' === $context['type'] ) {
			if ( 'publish' !== get_post_status( $target_id ) ) { return ''; }
			$url = get_permalink( $target_id );
		} else {
			if ( ! term_exists( $target_id ) ) { return ''; }
			$url = get_term_link( $target_id );
			if ( ! is_wp_error( $url ) ) { $url = Languages::url( $url, $language ); }
		}
		return ! is_wp_error( $url ) && is_string( $url ) ? $url : '';
	}

	private static function switcher_language_label( $code, array $language, array $settings ) {
		$parts = array();
		if ( ! empty( $settings['show_flag'] ) ) { $parts[] = '<span class="openlingua-switcher__flag" aria-hidden="true">' . self::switcher_flag_markup( $language['flag'] ?? '🌐' ) . '</span>'; }
		if ( ! empty( $settings['show_name'] ) ) { $parts[] = '<span class="openlingua-switcher__name">' . esc_html( $language['name'] ?? strtoupper( $code ) ) . '</span>'; }
		if ( ! empty( $settings['show_native_name'] ) && ( $language['native_name'] ?? $language['name'] ?? '' ) !== ( $language['name'] ?? '' ) ) { $parts[] = '<span class="openlingua-switcher__native">' . esc_html( $language['native_name'] ) . '</span>'; }
		return $parts ? implode( ' ', $parts ) : esc_html( strtoupper( $code ) );
	}

	private static function switcher_flag_markup( $flag ) {
		$flag = sanitize_text_field( (string) $flag );
		if ( preg_match( '#^https?://#i', $flag ) ) {
			return '<img class="openlingua-switcher__flag-image" src="' . esc_url( $flag ) . '" alt="" loading="lazy" decoding="async">';
		}
		$markup = function_exists( 'wp_staticize_emoji' ) ? wp_staticize_emoji( $flag ) : esc_html( $flag );
		return wp_kses( $markup, array( 'img' => array( 'src' => true, 'alt' => true, 'class' => true, 'style' => true, 'role' => true ) ) );
	}

	public static function locale( $locale ) {
		$languages = Languages::all();
		$current   = Languages::current();
		if ( is_admin() ) {
			$admin = \OpenLingua\Modules\Language_Settings::get()['admin_language'];
			if ( 'user' === $admin ) { return get_user_option( 'locale' ) ?: $locale; }
			$current = 'site-default' === $admin ? Languages::default_code() : $admin;
		}
		return isset( $languages[ $current ]['locale'] ) ? $languages[ $current ]['locale'] : $locale;
	}

	public static function language_attributes( $output ) {
		$language = Languages::all()[ Languages::current() ] ?? array();
		$direction = 'rtl' === ( $language['direction'] ?? 'ltr' ) ? 'rtl' : 'ltr';
		return 'lang="' . esc_attr( str_replace( '_', '-', $language['locale'] ?? Languages::current() ) ) . '" dir="' . esc_attr( $direction ) . '"';
	}
}

function register_string( $key, $text, $domain = 'default', $source_language = '' ) {
	return Strings::register( $key, $text, $domain, $source_language );
}

function translate_string( $key, $fallback = '', $domain = 'default', $language = '' ) {
	return Strings::translate( $key, $fallback, $domain, $language );
}

function translate_plural( $singular_key, $plural_key, $number, $singular, $plural, $domain = 'default', $language = '' ) {
	return Strings::translate_plural( $singular_key, $plural_key, $number, $singular, $plural, $domain, $language );
}

function translated_post_id( $post_id, $language ) {
	return Translations::translated_id( 'post', $post_id, $language );
}

function translated_term_id( $term_id, $language ) {
	return Translations::translated_id( 'term', $term_id, $language );
}

function register_provider( $provider ) {
	return \OpenLingua\Modules\Providers::register( $provider );
}

function enqueue_translation_job( $source_id, $target_id, $target_language, $provider_id ) {
	return \OpenLingua\Modules\Jobs::enqueue( $source_id, $target_id, $target_language, $provider_id );
}

function set_menu_translation( $location, $language, $menu_id ) {
	return \OpenLingua\Modules\Menus::set( $location, $language, $menu_id );
}
