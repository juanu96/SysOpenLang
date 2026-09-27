<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
}

namespace SysOpenLang {
	class Languages {
		public static function is_valid( $code ) { return in_array( $code, array( 'en', 'es', 'fr' ), true ); }
		public static function default_code() { return 'en'; }
	}
	class Translations {
		public static $rows = array();
		public static function row( $type, $id ) { return self::$rows[ $id ] ?? null; }
	}
}

namespace {
	class Slug_Test_DB {
		public $posts = 'wp_posts';
		public $conflicts = array();
		public function prepare( $query ) { return $query; }
		public function get_col() { return $this->conflicts; }
	}
	$wpdb = new Slug_Test_DB();
	require dirname( __DIR__ ) . '/src/class-content.php';

	function slug_assert( $expected, $actual, $message ) {
		if ( $expected !== $actual ) { fwrite( STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: {$actual}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	\SysOpenLang\Translations::$rows[1] = (object) array( 'language' => 'es' );
	\SysOpenLang\Translations::$rows[2] = (object) array( 'language' => 'en' );
	\SysOpenLang\Translations::$rows[3] = (object) array( 'language' => 'es' );

	$wpdb->conflicts = array( 2 );
	slug_assert( 'sample-entry', \SysOpenLang\Content::allow_slug_across_languages( 'sample-entry-2', 1, 'publish', 'sample_type', 0, 'sample-entry' ), 'allows the same slug in a different language' );

	$wpdb->conflicts = array( 3 );
	slug_assert( 'sample-entry-2', \SysOpenLang\Content::allow_slug_across_languages( 'sample-entry-2', 1, 'publish', 'sample_type', 0, 'sample-entry' ), 'keeps WordPress uniqueness within the same language' );

	$wpdb->conflicts = array( 99 );
	slug_assert( 'sample-entry', \SysOpenLang\Content::allow_slug_across_languages( 'sample-entry-2', 1, 'publish', 'sample_type', 0, 'sample-entry' ), 'treats unassigned legacy content as the default language' );

	echo "All SysOpenLang translated slug tests passed.\n";
}
