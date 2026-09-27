<?php
namespace SysOpenLang\Contracts { interface Module { public static function hooks(); } }

namespace SysOpenLang {
	final class Languages {
		public static function default_code() { return 'en'; }
		public static function current() { return 'es'; }
	}
	final class Strings {
		public static $registered = array();
		public static $translations = array();
		public static function register( $key, $text, $domain, $language ) { self::$registered[ $domain . ':' . $key ] = $text; return $text; }
		public static function translate( $key, $fallback, $domain, $language, $register_missing = true ) { return self::$translations[ $domain . ':' . $key . ':' . $language ] ?? $fallback; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['openlingua_discovery'] = false;
	function add_filter() {}
	function get_option( $key, $default = false ) { return 'openlingua_string_discovery' === $key ? $GLOBALS['openlingua_discovery'] : $default; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function absint( $value ) { return abs( (int) $value ); }

	require dirname( __DIR__ ) . '/src/modules/class-string-discovery.php';

	function strings_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	$key = substr( hash( 'sha256', "theme\0Recent Posts" ), 0, 32 );
	\SysOpenLang\Strings::$translations[ 'theme:' . $key . ':es' ] = 'Publicaciones recientes';
	$result = \SysOpenLang\Modules\String_Discovery::gettext( 'Recent Posts', 'Recent Posts', 'theme' );
	strings_assert( 'Publicaciones recientes' === $result, 'applies a stored gettext translation' );
	strings_assert( array() === \SysOpenLang\Strings::$registered, 'does not discover new strings while discovery is disabled' );

	$GLOBALS['openlingua_discovery'] = true;
	\SysOpenLang\Modules\String_Discovery::gettext( 'Recent Comments', 'Recent Comments', 'theme' );
	strings_assert( 1 === count( \SysOpenLang\Strings::$registered ), 'registers a missing string when discovery is enabled' );

	$context_key = substr( hash( 'sha256', "theme\0menu title\0Home" ), 0, 32 );
	\SysOpenLang\Strings::$translations[ 'theme:' . $context_key . ':es' ] = 'Inicio';
	strings_assert( 'Inicio' === \SysOpenLang\Modules\String_Discovery::gettext_with_context( 'Home', 'Home', 'menu title', 'theme' ), 'keeps contextual strings separate' );

	$plural_key = substr( hash( 'sha256', "theme\0plural\0Comments" ), 0, 32 );
	\SysOpenLang\Strings::$translations[ 'theme:' . $plural_key . ':es' ] = 'Comentarios';
	strings_assert( 'Comentarios' === \SysOpenLang\Modules\String_Discovery::ngettext( 'Comments', 'Comment', 'Comments', 2, 'theme' ), 'applies the plural translation' );

	echo "All SysOpenLang interface string tests passed.\n";
}
