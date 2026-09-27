<?php
// Lightweight secondary-query language-filter regression tests without booting WordPress.
define( 'ABSPATH', __DIR__ . '/' );

$openlingua_test_options = array(
	'openlingua_languages' => array( 'en' => array( 'name' => 'English' ), 'es' => array( 'name' => 'Español' ) ),
	'openlingua_default_language' => 'en',
	'openlingua_language_settings' => array( 'domains' => array( 'es' => 'https://es.example.test' ) ),
);
$openlingua_test_is_admin = false;
$openlingua_test_is_ajax = false;

function get_option( $key, $default = false ) { global $openlingua_test_options; return $openlingua_test_options[ $key ] ?? $default; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return stripslashes( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return (string) $url; }
function add_action() {}
function add_filter() {}
function is_admin() { global $openlingua_test_is_admin; return $openlingua_test_is_admin; }
function wp_doing_ajax() { global $openlingua_test_is_ajax; return $openlingua_test_is_ajax; }
function get_post_type_object( $post_type ) {
	return in_array( $post_type, array( 'post', 'listing' ), true ) ? (object) array( 'public' => true ) : (object) array( 'public' => false );
}

final class SysOpenLang_Test_Query {
	private $vars;
	private $main;
	private $attachment;
	private $search;
	public function __construct( $vars = array(), $main = false, $attachment = false, $search = false ) { $this->vars = $vars; $this->main = $main; $this->attachment = $attachment; $this->search = $search; }
	public function get( $key ) { return $this->vars[ $key ] ?? ''; }
	public function set( $key, $value ) { $this->vars[ $key ] = $value; }
	public function is_main_query() { return $this->main; }
	public function is_attachment() { return $this->attachment; }
	public function is_search() { return $this->search; }
}

require dirname( __DIR__ ) . '/src/class-languages.php';
require dirname( __DIR__ ) . '/src/class-content.php';

function openlingua_content_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) { fwrite( STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: {$actual}\n" ); exit( 1 ); }
	echo "PASS: {$message}\n";
}

\SysOpenLang\Languages::set_current( 'es' );
$query = new SysOpenLang_Test_Query( array( 'post_type' => 'listing' ) );
\SysOpenLang\Content::mark_frontend_query_language( $query );
openlingua_content_assert_same( 'es', $query->get( 'openlingua_language' ), 'marks a third-party secondary CPT query with the current language' );

$_SERVER['HTTP_REFERER'] = 'https://example.test/en/blog/';
$prefetched_query = new SysOpenLang_Test_Query( array( 'post_type' => 'listing' ) );
\SysOpenLang\Content::mark_frontend_query_language( $prefetched_query );
openlingua_content_assert_same( 'es', $prefetched_query->get( 'openlingua_language' ), 'uses the requested URL language instead of a prefetch referer on normal frontend queries' );
unset( $_SERVER['HTTP_REFERER'] );

$attachment_query = new SysOpenLang_Test_Query( array( 'post_type' => 'attachment' ) );
\SysOpenLang\Content::mark_frontend_query_language( $attachment_query );
openlingua_content_assert_same( '', $attachment_query->get( 'openlingua_language' ), 'keeps shared attachment queries unfiltered' );

$divi_layout_query = new SysOpenLang_Test_Query( array( 'post_type' => 'et_body_layout' ) );
\SysOpenLang\Content::mark_frontend_query_language( $divi_layout_query );
openlingua_content_assert_same( '', $divi_layout_query->get( 'openlingua_language' ), 'keeps non-public Divi Theme Builder layouts unfiltered' );

$suppressed_query = new SysOpenLang_Test_Query( array( 'post_type' => 'post', 'suppress_filters' => true ) );
\SysOpenLang\Content::mark_frontend_query_language( $suppressed_query );
openlingua_content_assert_same( '', $suppressed_query->get( 'openlingua_language' ), 'respects a query that explicitly suppresses filters' );

$openlingua_test_is_admin = true;
$openlingua_test_is_ajax = true;
$_SERVER['HTTP_REFERER'] = 'https://example.test/es/news/';
$ajax_query = new SysOpenLang_Test_Query( array( 'post_type' => 'post' ) );
\SysOpenLang\Content::mark_frontend_query_language( $ajax_query );
openlingua_content_assert_same( 'es', $ajax_query->get( 'openlingua_language' ), 'uses the referring language URL for an AJAX content query' );

echo "All SysOpenLang content-query tests passed.\n";
