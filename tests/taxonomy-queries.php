<?php
// Lightweight frontend term-query language-filter regression tests.
define( 'ABSPATH', __DIR__ . '/' );
define( 'REST_REQUEST', true );

$openlingua_test_options = array(
	'openlingua_languages' => array( 'en' => array( 'name' => 'English' ), 'es' => array( 'name' => 'Español' ) ),
	'openlingua_default_language' => 'en',
);
$openlingua_test_is_admin = false;
$pagenow = '';

function get_option( $key, $default = false ) { global $openlingua_test_options; return $openlingua_test_options[ $key ] ?? $default; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function wp_unslash( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function is_admin() { global $openlingua_test_is_admin; return $openlingua_test_is_admin; }
function get_current_screen() { return null; }
function add_action() {}
function add_filter() {}

final class SysOpenLang_Test_Term_WPDB {
	public $prefix = 'wp_';
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $slug_ids = array();
	public function prepare( $query, ...$values ) {
		foreach ( $values as $value ) {
			$replacement = false !== strpos( $query, '%i' ) ? (string) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$query = preg_replace( '/%[is]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function get_var( $query ) {
		if ( preg_match( "/t\\.slug = '([^']+)'/", $query, $matches ) ) { return $this->slug_ids[ $matches[1] ] ?? 0; }
		return 0;
	}
}
$wpdb = new SysOpenLang_Test_Term_WPDB();

require dirname( __DIR__ ) . '/src/class-languages.php';
require dirname( __DIR__ ) . '/src/class-database.php';
require dirname( __DIR__ ) . '/src/class-taxonomies.php';

function openlingua_taxonomy_query_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	echo "PASS: {$message}\n";
}

\SysOpenLang\Languages::set_current( 'es' );
$clauses = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'category' ), array() );
openlingua_taxonomy_query_assert( false !== strpos( $clauses['where'], 'ol_term_lang' ) && false !== strpos( $clauses['where'], 'ol_term_default' ) && false !== strpos( $clauses['where'], 'ol_term_target' ), 'uses a translated term first and otherwise keeps the default-language term as a frontend fallback' );
openlingua_taxonomy_query_assert( false !== strpos( $clauses['where'], 'ol_term_relationship' ) && false !== strpos( $clauses['where'], 'ol_term_post_language' ) && false !== strpos( $clauses['where'], "language = 'es'" ), 'keeps a translated term visible when it is physically assigned to a post in the requested language' );

$relationship = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'type_of_property' ), array( 'object_ids' => array( 27564740 ), 'openlingua_language' => 'es' ) );
openlingua_taxonomy_query_assert( ' WHERE 1=1' === $relationship['where'], 'returns every physical term assigned to a language-scoped post without applying a second global term filter' );

$hydrated_terms = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'type_of_property' ), array( 'include' => array( 126 ), 'openlingua_language' => 'en' ) );
openlingua_taxonomy_query_assert( ' WHERE 1=1' === $hydrated_terms['where'], 'keeps explicit term IDs intact while WPGraphQL hydrates a resolved connection' );

$wpdb->slug_ids = array( 'escazu' => 55 );
openlingua_taxonomy_query_assert( 'escazu-es' === \SysOpenLang\Taxonomies::unique_translation_slug( 'escazu', 'location', 'es' ), 'creates a deterministic language-specific slug when the requested slug is already in use' );
$wpdb->slug_ids['escazu-es'] = 56;
openlingua_taxonomy_query_assert( 'escazu-es-2' === \SysOpenLang\Taxonomies::unique_translation_slug( 'escazu', 'location', 'es' ), 'adds a numeric suffix when the language-specific slug is already in use' );
openlingua_taxonomy_query_assert( 'escazu-es' === \SysOpenLang\Taxonomies::unique_translation_slug( 'escazu-es', 'location', 'es', 56 ), 'keeps the existing slug when it belongs to the term being edited' );

$default_clauses = \SysOpenLang\Taxonomies::filter_language_clauses( array( 'where' => ' WHERE 1=1' ), 'en' );
openlingua_taxonomy_query_assert( false !== strpos( $default_clauses['where'], 'NOT EXISTS' ) && false !== strpos( $default_clauses['where'], "language = 'en'" ), 'keeps unassigned legacy terms and English terms in the default language' );

\SysOpenLang\Languages::set_current( 'en' );
$_GET['lang'] = 'es';
$rest = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'category' ), array() );
openlingua_taxonomy_query_assert( false !== strpos( $rest['where'], "language = 'es'" ), 'uses the requested language for REST taxonomy queries' );
unset( $_GET['lang'] );

$skipped = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'category' ), array( 'openlingua_skip_language_filter' => true ) );
openlingua_taxonomy_query_assert( ' WHERE 1=1' === $skipped['where'], 'allows internal callers to explicitly bypass the frontend taxonomy language filter' );

$graphql_explicit = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'category' ), array( 'suppress_filter' => true, 'openlingua_language' => 'es' ) );
openlingua_taxonomy_query_assert( false !== strpos( $graphql_explicit['where'], "language = 'es'" ), 'honors an explicit language even when a third-party term query suppresses ordinary filters' );

$openlingua_test_is_admin = true;
$pagenow = 'edit-tags.php';
$admin_terms = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'category' ), array() );
openlingua_taxonomy_query_assert( false !== strpos( $admin_terms['where'], "language = 'en'" ), 'keeps translated terms out of the native taxonomy list for the active admin language' );

$pagenow = '';
$admin = \SysOpenLang\Taxonomies::filter_frontend_term_clauses( array( 'where' => ' WHERE 1=1' ), array( 'category' ), array() );
openlingua_taxonomy_query_assert( ' WHERE 1=1' === $admin['where'], 'keeps taxonomy management screens unfiltered' );

echo "All SysOpenLang taxonomy-query tests passed.\n";
