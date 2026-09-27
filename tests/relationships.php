<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function home_url() { return 'https://example.test/'; }
	function url_to_postid( $url ) { return false !== strpos( $url, '/services/' ) ? 10 : 0; }
	function get_post_status( $id ) { return 20 === (int) $id ? 'publish' : 'draft'; }
	function get_permalink( $id ) { return 20 === (int) $id ? 'https://example.test/es/servicios/' : ''; }
}

namespace SysOpenLang {
	final class Translations {
		public static $target = 20;
		public static function translated_id( $type, $id, $language ) {
			return 'post' === $type && 10 === (int) $id && 'es' === $language ? self::$target : 0;
		}
		public static function translated_id_with_fallback( $type, $id, $language ) { return self::translated_id( $type, $id, $language ); }
	}

	require dirname( __DIR__ ) . '/src/class-gutenberg-content.php';

	function relationships_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	relationships_assert( 'https://example.test/es/servicios/' === Gutenberg_Content::map_internal_url( 'https://example.test/services/', 'es' ), 'maps an internal link to its published translation' );
	relationships_assert( 'https://example.test/es/servicios/#contact' === Gutenberg_Content::map_internal_url( 'https://example.test/services/#contact', 'es' ), 'preserves an internal link fragment' );
	relationships_assert( 'https://example.test/es/servicios/?view=grid#contact' === Gutenberg_Content::map_internal_url( 'https://example.test/services/?view=grid#contact', 'es' ), 'preserves internal link query arguments' );
	relationships_assert( 'https://external.test/services/' === Gutenberg_Content::map_internal_url( 'https://external.test/services/', 'es' ), 'does not rewrite external links' );
	relationships_assert( 'mailto:hello@example.test' === Gutenberg_Content::map_internal_url( 'mailto:hello@example.test', 'es' ), 'does not rewrite special links' );
	Translations::$target = 30;
	relationships_assert( 'https://example.test/services/' === Gutenberg_Content::map_internal_url( 'https://example.test/services/', 'es' ), 'keeps the source URL when the translation is not published' );

	echo "All SysOpenLang relationship tests passed.\n";
}
