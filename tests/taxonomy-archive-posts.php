<?php
// Regression test for translated taxonomy archives inheriting source post relationships.
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $text ) { return $text; }
	function add_action() {}
	function add_filter() {}
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	function is_admin() { return false; }
	function is_tax() { return true; }
	function is_category() { return false; }
	function is_tag() { return false; }
	function get_queried_object() { return (object) array( 'term_id' => 141, 'term_taxonomy_id' => 141, 'taxonomy' => 'state_listing' ); }

	final class SysOpenLang_Tax_Archive_Query {
		private $vars;
		public function __construct( $vars = array() ) { $this->vars = $vars; }
		public function get( $key ) { return $this->vars[ $key ] ?? ''; }
		public function is_main_query() { return true; }
	}

	final class SysOpenLang_Tax_Archive_DB {
		public $posts = 'wp_posts';
		public $term_taxonomy = 'wp_term_taxonomy';
		public $term_relationships = 'wp_term_relationships';
		public function prepare( $query, ...$values ) {
			foreach ( $values as $value ) {
				$replacement = is_numeric( $value ) ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
				$query = preg_replace( '/%[isd]/', $replacement, $query, 1 );
			}
			return $query;
		}
		public function get_var( $query ) {
			return false !== strpos( $query, 'term_id = 80' ) && false !== strpos( $query, "taxonomy = 'state_listing'" ) ? 80 : 0;
		}
	}
	$wpdb = new SysOpenLang_Tax_Archive_DB();
}

namespace SysOpenLang {
	final class Languages {
		public static function current() { return 'es'; }
		public static function default_code() { return 'en'; }
		public static function is_valid( $language ) { return in_array( $language, array( 'en', 'es' ), true ); }
	}
	final class Database {
		public static function table( $name ) { unset( $name ); return 'wp_openlingua_translations'; }
	}
	final class Translations {
		public static function row( $type, $id ) {
			return 'term' === $type && 141 === (int) $id ? (object) array( 'language' => 'es', 'group_uuid' => 'term-group-sell' ) : null;
		}
		public static function translated_id( $type, $id, $language ) {
			return 'term' === $type && 141 === (int) $id && 'en' === $language ? 80 : 0;
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/class-content.php';
	$clauses = \SysOpenLang\Content::filter_frontend_posts(
		array(
			'where'  => ' WHERE 1=1 AND wp_term_relationships.term_taxonomy_id IN (141)',
			'orderby'=> '',
		),
		new SysOpenLang_Tax_Archive_Query()
	);
	if ( false === strpos( $clauses['where'], 'ol_source_rel.term_taxonomy_id = 80' ) || false === strpos( $clauses['where'], "ol_source.language = 'en'" ) || false === strpos( $clauses['where'], "ol_current.language = 'es'" ) ) {
		fwrite( STDERR, "FAIL: translated taxonomy archive does not include source-language post relationships.\n" . $clauses['where'] . "\n" );
		exit( 1 );
	}
	echo "PASS: translated taxonomy archive includes posts whose source belongs to the original term.\n";
	echo "All SysOpenLang taxonomy archive post tests passed.\n";
}
