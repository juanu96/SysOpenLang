<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$openlingua_test_filters = array();
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		global $openlingua_test_filters;
		$openlingua_test_filters[ $hook ] = array( $callback, $priority, $accepted_args );
	}
	function add_action() {}
	function is_admin() { return false; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function get_option( $key, $default = false ) { return 'page_on_front' === $key ? 10 : $default; }
	function home_url( $path = '/' ) { return 'https://example.test' . $path; }
}

namespace SysOpenLang\Contracts { interface Module {} }
namespace SysOpenLang {
	class Languages {
		public static function current() { return 'es'; }
		public static function is_valid( $language ) { return 'es' === $language; }
		public static function url( $url, $language ) { return 'https://example.test/' . $language . '/'; }
		public static function default_code() { return 'en'; }
		public static function all() { return array( 'en' => array(), 'es' => array() ); }
	}
	class Translations {
		public static function group( $type, $id ) { return array( 'en' => 10, 'es' => 20 ); }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/modules/class-menus.php';
	function menu_home_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	\SysOpenLang\Modules\Menus::hooks();
	global $openlingua_test_filters;
	menu_home_assert( isset( $openlingua_test_filters['wp_nav_menu_objects'] ), 'registers the native WordPress menu-item render hook' );
	$front = (object) array( 'type' => 'post_type', 'object' => 'page', 'object_id' => 20, 'url' => 'https://example.test/es/inicio/' );
	$other = (object) array( 'type' => 'post_type', 'object' => 'page', 'object_id' => 21, 'url' => 'https://example.test/es/contacto/' );
	$custom = (object) array( 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => 'https://outside.example/' );
	$items = \SysOpenLang\Modules\Menus::localize_front_page_links( array( $front, $other, $custom ) );
	menu_home_assert( 'https://example.test/es/' === $items[0]->url, 'maps the translated static front page menu item to the language root' );
	menu_home_assert( 'https://example.test/es/contacto/' === $items[1]->url, 'does not alter another page menu item' );
	menu_home_assert( 'https://outside.example/' === $items[2]->url, 'does not alter a custom external menu item' );
	echo "All SysOpenLang front-page menu link tests passed.\n";
}
