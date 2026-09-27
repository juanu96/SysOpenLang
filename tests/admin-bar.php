<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$openlingua_admin_bar_is_translation = true;
	function is_admin() { return false; }
	function is_admin_bar_showing() { return true; }
	function is_singular() { return true; }
	function get_queried_object_id() { return 202; }
	function current_user_can() { return true; }
	function absint( $value ) { return abs( (int) $value ); }
	function get_permalink( $id ) { return 'https://example.test/es/page-' . $id . '/'; }
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function esc_html__( $text ) { return $text; }
	function esc_attr__( $text ) { return $text; }
	function add_action() {}
}

namespace SysOpenLang {
	final class Translations {
		public static function row() {
			global $openlingua_admin_bar_is_translation;
			return $openlingua_admin_bar_is_translation ? (object) array( 'source_language' => 'en' ) : (object) array( 'source_language' => '' );
		}
		public static function translated_id() { return 101; }
	}

	final class Test_Admin_Bar {
		public $nodes = array();
		public function add_node( $node ) { $this->nodes[] = $node; }
	}

	require dirname( __DIR__ ) . '/src/class-translation-editor.php';

	$bar = new Test_Admin_Bar();
	Translation_Editor::admin_bar_link( $bar );
	if ( 1 !== count( $bar->nodes ) || 'openlingua-edit-translation' !== $bar->nodes[0]['id'] || false === strpos( $bar->nodes[0]['href'], 'source_id=101' ) || false === strpos( $bar->nodes[0]['href'], 'target_id=202' ) ) {
		fwrite( STDERR, "FAIL: translated page admin-bar link\n" );
		exit( 1 );
	}
	echo "PASS: adds an editor link for the current translated page\n";

	$openlingua_admin_bar_is_translation = false;
	$bar = new Test_Admin_Bar();
	Translation_Editor::admin_bar_link( $bar );
	if ( $bar->nodes ) { fwrite( STDERR, "FAIL: original page admin-bar link\n" ); exit( 1 ); }
	echo "PASS: keeps the link hidden on original content\n";
}
