<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function is_admin() { return false; }
	function absint( $value ) { return abs( (int) $value ); }
	function get_post_status( $id ) { return 20 === (int) $id ? 'publish' : ( 30 === (int) $id ? 'draft' : 'publish' ); }
	function get_post( $id ) { return (object) array( 'ID' => (int) $id, 'post_type' => 'wp_template' ); }
	function _build_block_template_result_from_post( $post ) { return (object) array( 'wp_id' => $post->ID, 'translated' => true ); }
}

namespace SysOpenLang {
	final class Languages {
		public static $current = 'es';
		public static function current() { return self::$current; }
		public static function default_code() { return 'en'; }
	}
	final class Translations {
		public static function translated_id( $type, $id, $language ) {
			if ( 'post' !== $type || 'es' !== $language ) { return 0; }
			return 10 === (int) $id ? 20 : ( 11 === (int) $id ? 30 : 0 );
		}
		public static function translated_id_with_fallback( $type, $id, $language ) { return self::translated_id( $type, $id, $language ); }
	}

	require dirname( __DIR__ ) . '/src/class-global-content.php';

	function global_content_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	$source = (object) array( 'wp_id' => 10 );
	$result = Global_Content::translate_template( $source, 'theme//index', 'wp_template' );
	global_content_assert( 20 === $result->wp_id, 'uses a published block template translation' );

	$draft_source = (object) array( 'wp_id' => 11 );
	global_content_assert( $draft_source === Global_Content::translate_template( $draft_source, 'theme//single', 'wp_template' ), 'does not render a draft template translation' );

	Languages::$current = 'en';
	global_content_assert( $source === Global_Content::translate_template( $source, 'theme//index', 'wp_template' ), 'keeps the source template in the default language' );
	global_content_assert( $source === Global_Content::translate_template( $source, 'theme//index', 'other_type' ), 'ignores unrelated template types' );

	echo "All SysOpenLang global content tests passed.\n";
}
