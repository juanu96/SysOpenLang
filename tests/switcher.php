<?php
namespace SysOpenLang\Modules {
	final class Language_Settings {
		public static function get() {
			return array( 'switcher' => array( 'show_flag' => false, 'show_name' => true, 'show_native_name' => false, 'show_current' => true, 'dropdown' => true ) );
		}
	}
}

namespace SysOpenLang {
	final class Languages {
		public static $current = 'en';
		public static function current() { return self::$current; }
		public static function all() { return self::public_all(); }
		public static function public_all() { return array( 'en' => array( 'name' => 'English' ), 'es' => array( 'name' => 'Spanish' ), 'fr' => array( 'name' => 'French' ) ); }
		public static function url( $url, $code ) { return $url; }
	}
	final class Translations {
		public static $group = array( 'en' => 10, 'es' => 20 );
		public static function group( $type, $id ) { return self::$group; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'SYSOPENLANG_FILE', dirname( __DIR__ ) . '/sysopenlang.php' );
	define( 'SYSOPENLANG_VERSION', 'test' );
	$GLOBALS['openlingua_post_statuses'] = array( 10 => 'publish', 20 => 'draft' );

	function shortcode_atts( $defaults, $values ) { return array_merge( $defaults, $values ); }
	function get_queried_object_id() { return 10; }
	function is_singular() { return true; }
	function is_category() { return false; }
	function is_tag() { return false; }
	function is_tax() { return false; }
	function absint( $value ) { return abs( (int) $value ); }
	function get_post_status( $id ) { return $GLOBALS['openlingua_post_statuses'][ $id ] ?? false; }
	function get_permalink( $id ) { return 'https://example.test/page-' . $id . '/'; }
	function home_url() { return 'https://example.test/'; }
	function term_exists() { return false; }
	function get_term_link() { return ''; }
	function is_wp_error() { return false; }
	function sanitize_text_field( $value ) { return (string) $value; }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $value ) { return esc_html( $value ); }
	function esc_url( $value ) { return (string) $value; }
	function esc_attr__( $value ) { return $value; }
	function wp_kses( $value ) { return $value; }
	function plugins_url( $path ) { return $path; }
	function wp_enqueue_style() {}
	function wp_enqueue_script() {}

	require dirname( __DIR__ ) . '/src/class-plugin.php';

	function switcher_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	$hidden = \SysOpenLang\Plugin::switcher( array( 'context' => 'menu' ) );
	switcher_assert( false !== strpos( $hidden, 'English' ), 'keeps the current language visible when no translation exists' );
	switcher_assert( false !== strpos( $hidden, 'openlingua-switcher--single' ), 'renders a non-interactive current-language indicator' );
	switcher_assert( false === strpos( $hidden, '<details' ) && false === strpos( $hidden, 'Spanish' ) && false === strpos( $hidden, 'French' ), 'does not render an empty dropdown or unavailable languages' );

	$GLOBALS['openlingua_post_statuses'][20] = 'publish';
	$visible = \SysOpenLang\Plugin::switcher( array( 'context' => 'menu' ) );
	switcher_assert( false !== strpos( $visible, 'Spanish' ), 'shows an available published translation' );
	switcher_assert( false === strpos( $visible, 'French' ), 'hides a language without a translation' );
	switcher_assert( false === strpos( $visible, 'English</a>' ), 'keeps the current language in the dropdown summary only' );

	$GLOBALS['openlingua_post_statuses'][20] = 'draft';
	$draft_hidden = \SysOpenLang\Plugin::switcher( array( 'context' => 'menu' ) );
	switcher_assert( false !== strpos( $draft_hidden, 'English' ) && false === strpos( $draft_hidden, 'Spanish' ), 'hides draft translations but keeps the current language' );

	echo "All SysOpenLang switcher availability tests passed.\n";
}
