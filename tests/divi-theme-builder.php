<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ET_THEME_BUILDER_HEADER_LAYOUT_POST_TYPE', 'et_header_layout' );
	define( 'ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE', 'et_body_layout' );
	define( 'ET_THEME_BUILDER_FOOTER_LAYOUT_POST_TYPE', 'et_footer_layout' );
	function __( $text ) { return $text; }
	function absint( $value ) { return abs( (int) $value ); }
	function is_admin() { return false; }
	function get_post_status( $id ) { return 202 === (int) $id ? 'publish' : 'draft'; }
	function get_post_type( $id ) { return 202 === (int) $id ? 'et_body_layout' : 'post'; }
}

namespace SysOpenLang {
	final class Languages {
		public static $current = 'es';
		public static function current() { return self::$current; }
		public static function default_code() { return 'en'; }
	}
	final class Translations {
		public static function translated_id( $type, $id, $language ) {
			if ( 'post' === $type && 101 === (int) $id && 'es' === $language ) { return 202; }
			if ( 'term' === $type && 12 === (int) $id && 'es' === $language ) { return 34; }
			return 0;
		}
	}

	require dirname( __DIR__ ) . '/src/class-divi-theme-builder.php';

	function divi_tb_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	$templates = array(
		array(
			'id' => 1, 'default' => true, 'title' => '',
			'layouts' => array(
				'header' => array( 'id' => 100, 'enabled' => true, 'global' => true ),
				'body' => array( 'id' => 101, 'enabled' => true, 'global' => false ),
				'footer' => array( 'id' => 0, 'enabled' => true, 'global' => false ),
			),
		),
		array(
			'id' => 2, 'default' => false, 'title' => 'Sample archive',
			'layouts' => array(
				'header' => array( 'id' => 100, 'enabled' => true, 'global' => true ),
				'body' => array( 'id' => 0, 'enabled' => true, 'global' => false ),
				'footer' => array( 'id' => 103, 'enabled' => false, 'global' => false ),
			),
		),
	);
	$rows = Divi_Theme_Builder::rows_from_templates( $templates );
	divi_tb_assert( 3 === count( $rows ), 'deduplicates shared Theme Builder layouts' );
	divi_tb_assert( array( 'Default Website Template', 'Sample archive' ) === $rows[0]['contexts'], 'keeps every template context for a shared layout' );
	divi_tb_assert( false === $rows[2]['enabled'], 'preserves disabled layout state' );

	$layouts = array( 'et_body_layout' => array( 'id' => 101, 'enabled' => true, 'override' => true ) );
	$translated = Divi_Theme_Builder::translate_template_layouts( $layouts );
	divi_tb_assert( 202 === $translated['et_body_layout']['id'], 'uses the published layout translation for the current language' );
	divi_tb_assert( 34 === Divi_Theme_Builder::translate_condition_object_id( 12, 'taxonomy', 'category' ), 'maps translated taxonomy conditions' );
	Languages::$current = 'en';
	divi_tb_assert( 101 === Divi_Theme_Builder::translate_template_layouts( $layouts )['et_body_layout']['id'], 'keeps the original layout in the default language' );

	echo "All SysOpenLang Divi Theme Builder tests passed.\n";
}
