<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers and translates visible strings in rendered shortcode output.
 *
 * Shortcode authors do not need to depend on SysOpenLang. The original callback
 * renders normally and this class processes its final HTML through WordPress'
 * do_shortcode_tag filter.
 */
final class Shortcode_Content {
	private static $translated = array();

	public static function hooks() {
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'translate_output' ), 20, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	public static function translate_output( $output, $tag, $attributes = array(), $match = array() ) {
		if ( ! is_string( $output ) || '' === trim( $output ) || ! is_string( $tag ) || ! self::is_supported( $tag ) ) {
			return $output;
		}

		$domain = 'shortcode-' . sanitize_key( $tag );
		$output = self::translate_html( $output, $domain );
		return self::mark_dynamic_root( $output, $tag );
	}

	/** Translates visible text and accessibility attributes in rendered HTML. */
	public static function translate_html( $html, $domain ) {
		$html = self::translate_element_text( (string) $html, sanitize_key( $domain ) );
		return self::translate_attributes( $html, sanitize_key( $domain ) );
	}

	public static function assets() {
		if ( is_admin() ) { return; }
		$preloaded = self::preloaded_translations( Languages::current() );
		wp_enqueue_script( 'openlingua-dynamic-shortcodes', plugins_url( 'assets/dynamic-shortcodes.js', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION, false );
		wp_localize_script( 'openlingua-dynamic-shortcodes', 'SysOpenLangShortcodes', array(
			'endpoint'       => esc_url_raw( rest_url( 'sysopenlang/v1/shortcode-strings' ) ),
			'nonce'          => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'language'       => Languages::current(),
			'sourceLanguage' => Languages::default_code(),
			'preloaded'      => $preloaded,
			'cacheKey'       => 'openlingua-shortcodes-' . SYSOPENLANG_VERSION . '-' . Languages::current(),
		) );
	}

	private static function preloaded_translations( $language ) {
		if ( ! Languages::is_valid( $language ) || Languages::default_code() === $language ) { return array(); }
		global $wpdb;
		$prefix = $wpdb->esc_like( 'shortcode-' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT domain, string_key, source_text, translations FROM %i WHERE domain LIKE %s', Database::table( 'strings' ), $prefix ) );
		$result = array();
		foreach ( (array) $rows as $row ) {
			$translations = json_decode( $row->translations, true ) ?: array();
			if ( empty( $translations[ $language ] ) ) { continue; }
			$shortcode = substr( $row->domain, strlen( 'shortcode-' ) );
			$kind = preg_replace( '/-[a-f0-9]{24}$/', '', $row->string_key );
			$source = trim( preg_replace( '/\s+/u', ' ', $row->source_text ) );
			$result[ $shortcode . '|' . $kind . '|' . $source ] = (string) $translations[ $language ];
		}
		return $result;
	}

	public static function rest_routes() {
		register_rest_route( 'sysopenlang/v1', '/shortcode-strings', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'dynamic_strings' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'shortcode' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'language'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				'entries'   => array( 'required' => true, 'type' => 'array' ),
			),
		) );
	}

	public static function dynamic_strings( $request ) {
		$tag      = sanitize_key( (string) $request->get_param( 'shortcode' ) );
		$language = sanitize_key( (string) $request->get_param( 'language' ) );
		$entries  = $request->get_param( 'entries' );
		if ( ! Languages::is_valid( $language ) || ! is_array( $entries ) ) {
			return new \WP_Error( 'openlingua_invalid_shortcode_strings', __( 'Invalid shortcode strings request.', 'sysopenlang' ), array( 'status' => 400 ) );
		}

		$entries = array_slice( $entries, 0, 50 );
		if ( ! self::is_supported( $tag ) ) {
			return rest_ensure_response( array( 'translations' => self::fallback_entry_texts( $entries ) ) );
		}
		$domain  = 'shortcode-' . $tag;
		$can_discover = current_user_can( 'manage_options' ) && Languages::default_code() === Languages::current();
		$result = array();
		global $wpdb;
		$table = Database::table( 'strings' );
		foreach ( $entries as $entry ) {
			$text = isset( $entry['text'] ) ? sanitize_text_field( (string) $entry['text'] ) : '';
			$kind = isset( $entry['kind'] ) ? sanitize_key( (string) $entry['kind'] ) : 'element-text';
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
			if ( '' === $text || strlen( $text ) > 1000 || ! preg_match( '/\p{L}/u', $text ) ) { $result[] = $text; continue; }
			$key = sanitize_key( $kind . '-' . substr( hash( 'sha256', $text ), 0, 24 ) );
			if ( $can_discover ) { Strings::register( $key, $text, $domain, Languages::default_code() ); }
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT source_text, translations FROM %i WHERE domain = %s AND string_key = %s', $table, $domain, $key ) );
			if ( ! $row ) { $result[] = $text; continue; }
			$translations = json_decode( $row->translations, true ) ?: array();
			$result[] = ! empty( $translations[ $language ] ) ? $translations[ $language ] : $row->source_text;
		}
		return rest_ensure_response( array( 'translations' => $result ) );
	}

	private static function fallback_entry_texts( array $entries ) {
		$result = array();
		foreach ( $entries as $entry ) {
			$text = isset( $entry['text'] ) ? sanitize_text_field( (string) $entry['text'] ) : '';
			$result[] = trim( preg_replace( '/\s+/u', ' ', $text ) );
		}
		return $result;
	}

	public static function is_supported( $tag ) {
		$tag = sanitize_key( $tag );
		if ( '' === $tag ) { return false; }
		// Process every registered third-party shortcode, not a hand-maintained
		// list. Native Divi modules are handled by Divi_Content, which prevents
		// their container output from duplicating fields in this generic path.
		global $shortcode_tags;
		$excluded = array( 'audio', 'caption', 'embed', 'gallery', 'playlist', 'video', 'wp_caption', 'sysopenlang_switcher', 'openlingua_switcher' );
		$supported = ! in_array( $tag, $excluded, true ) && 0 !== strpos( $tag, 'et_pb_' ) && isset( $shortcode_tags[ $tag ] );
		return (bool) apply_filters( 'openlingua_translate_shortcode_output', $supported, $tag );
	}

	private static function mark_dynamic_root( $html, $tag ) {
		if ( false !== stripos( $html, 'data-openlingua-shortcode=' ) ) { return $html; }
		return preg_replace_callback( '~<([a-z][a-z0-9:-]*)([^>]*)>~i', static function ( $matches ) use ( $tag ) {
			$attributes = $matches[2] . ' data-openlingua-shortcode="' . esc_attr( sanitize_key( $tag ) ) . '"';
			return '<' . $matches[1] . $attributes . '>';
		}, $html, 1 );
	}

	private static function translate_element_text( $html, $domain ) {
		$tags = array( 'a', 'button', 'caption', 'dt', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'label', 'legend', 'option', 'summary', 'th' );
		$tags = (array) apply_filters( 'openlingua_shortcode_text_tags', $tags, $domain );
		$tags = array_values( array_filter( array_map( 'sanitize_key', $tags ) ) );
		if ( ! $tags ) { return $html; }

		$pattern = '~<(' . implode( '|', array_map( 'preg_quote', $tags ) ) . ')(\s[^>]*)?>([^<]*)</\1\s*>~iu';
		return preg_replace_callback( $pattern, static function ( $matches ) use ( $domain ) {
			$source = html_entity_decode( $matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$translated = self::translate_text( $source, 'element-' . strtolower( $matches[1] ), $domain );
			if ( $translated === $source ) { return $matches[0]; }
			return '<' . $matches[1] . ( $matches[2] ?? '' ) . '>' . esc_html( $translated ) . '</' . $matches[1] . '>';
		}, $html );
	}

	private static function translate_attributes( $html, $domain ) {
		$attributes = array( 'aria-label', 'alt', 'placeholder', 'title' );
		$attributes = (array) apply_filters( 'openlingua_shortcode_text_attributes', $attributes, $domain );
		$attributes = array_values( array_filter( array_map( 'sanitize_key', $attributes ) ) );
		if ( ! $attributes ) { return $html; }

		$attribute_pattern = '~\b(' . implode( '|', array_map( 'preg_quote', $attributes ) ) . ')\s*=\s*(["\'])(.*?)\2~iu';
		return preg_replace_callback( '~<(?!/?(?:script|style|code|pre)\b)[a-z][^>]*>~iu', static function ( $tag_match ) use ( $attribute_pattern, $domain ) {
			return preg_replace_callback( $attribute_pattern, static function ( $matches ) use ( $domain ) {
				$source = html_entity_decode( $matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$translated = self::translate_text( $source, 'attribute-' . strtolower( $matches[1] ), $domain );
				if ( $translated === $source ) { return $matches[0]; }
				return $matches[1] . '=' . $matches[2] . esc_attr( $translated ) . $matches[2];
			}, $tag_match[0] );
		}, $html );
	}

	private static function translate_text( $text, $kind, $domain ) {
		$leading  = preg_match( '/^\s+/u', $text, $match ) ? $match[0] : '';
		$trailing = preg_match( '/\s+$/u', $text, $match ) ? $match[0] : '';
		$source   = trim( preg_replace( '/\s+/u', ' ', $text ) );

		if ( '' === $source || ! preg_match( '/\p{L}/u', $source ) ) { return $text; }

		$key = sanitize_key( $kind . '-' . substr( hash( 'sha256', $source ), 0, 24 ) );
		$cache_key = $domain . ':' . $key . ':' . Languages::current();
		if ( ! array_key_exists( $cache_key, self::$translated ) ) {
			// Only the source-language render may create or refresh a discovered
			// source string. A target-language page can legitimately contain a
			// fallback source value, and must never overwrite that source text.
			self::$translated[ $cache_key ] = Strings::translate( $key, $source, $domain, '', Languages::default_code() === Languages::current() );
		}
		return $leading . self::$translated[ $cache_key ] . $trailing;
	}
}
