<?php
namespace SysOpenLang\Modules;

defined( 'ABSPATH' ) || exit;

trait Provider_Secrets {
	private static function secret_key() { return hash( 'sha256', wp_salt( 'auth' ), true ); }
	private static function encrypt_secret( $value ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return ''; }
		$iv = random_bytes( 12 ); $tag = '';
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return $cipher ? 'v1:' . base64_encode( $iv . $tag . $cipher ) : '';
	}
	private static function decrypt_secret( $value ) {
		if ( ! is_string( $value ) || 0 !== strpos( $value, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) { return ''; }
		$data = base64_decode( substr( $value, 3 ), true );
		if ( false === $data || strlen( $data ) < 29 ) { return ''; }
		$plain = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) );
		return is_string( $plain ) ? $plain : '';
	}
	private static function json_result( $text, array $segments, $provider ) {
		$text = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $text ) ) );
		$result = json_decode( $text, true );
		/* translators: %s: translation provider name. */
		if ( ! is_array( $result ) ) { return new \WP_Error( 'openlingua_' . $provider . '_response', sprintf( __( '%s returned a response that SysOpenLang could not read.', 'sysopenlang' ), ucfirst( $provider ) ) ); }
		return array_intersect_key( $result, $segments );
	}
	private static function translation_prompt( array $segments, $source_language, $target_language ) {
		return 'Translate every value in this JSON object from ' . $source_language . ' to ' . $target_language . '. Preserve every JSON key, HTML tag, shortcode, placeholder, URL, number, unit, and brand name exactly. Translate only human-readable text. Return only one valid JSON object with all the same keys and string values. Do not add Markdown, explanations, styles, tags, or content.\n\n' . wp_json_encode( $segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
