<?php
define( 'ABSPATH', __DIR__ . '/' );

$openlingua_test_filters = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $openlingua_test_filters;
	$openlingua_test_filters[ $hook ] = array( $callback, $priority, $accepted_args );
}
function is_admin() { return false; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function get_option( $key, $default = false ) {
	$options = array(
		'openlingua_languages' => array( 'en' => array(), 'es' => array() ),
		'openlingua_default_language' => 'en',
		'openlingua_language_settings' => array( 'url_mode' => 'directory' ),
	);
	return $options[ $key ] ?? $default;
}
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function remove_query_arg( $key, $url ) {
	$parts = parse_url( $url );
	if ( false === $parts ) { return $url; }
	$query = array();
	parse_str( $parts['query'] ?? '', $query );
	unset( $query[ $key ] );
	$base = ( $parts['scheme'] ?? '' ) ? $parts['scheme'] . '://' . ( $parts['host'] ?? '' ) : '';
	$base .= $parts['path'] ?? '';
	return $base . ( $query ? '?' . http_build_query( $query ) : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
}
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }

require dirname( __DIR__ ) . '/src/class-languages.php';
require dirname( __DIR__ ) . '/src/class-divi-content.php';

function divi_link_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	echo "PASS: {$message}\n";
}

\SysOpenLang\Languages::set_current( 'es' );
\SysOpenLang\Divi_Content::hooks();
global $openlingua_test_filters;
divi_link_assert( isset( $openlingua_test_filters['et_pb_module_shortcode_attributes'] ), 'registers the native Divi module render hook' );

$links = \SysOpenLang\Divi_Content::localize_home_links( array(
	'logo_link_url' => 'https://example.test/',
	'url' => '/en/',
	'button_url' => 'https://example.test/contact/',
	'external_url' => 'https://outside.example/',
	'logo_link_url_with_fragment' => 'https://example.test/?lang=en#top',
	'title_text_color' => '#FFFFFF',
	'background_color' => '#000000',
	'anchor_link_url' => '#top',
) );
divi_link_assert( 'https://example.test/es/' === $links['logo_link_url'], 'maps a Divi Menu logo home link to the current language' );
divi_link_assert( 'https://example.test/es/' === $links['url'], 'maps a relative language-home image link to the current language' );
divi_link_assert( 'https://example.test/contact/' === $links['button_url'], 'does not alter an explicit local page URL' );
divi_link_assert( 'https://outside.example/' === $links['external_url'], 'does not alter an external URL' );
divi_link_assert( 'https://example.test/es/#top' === $links['logo_link_url_with_fragment'], 'preserves a home-link fragment while replacing its language' );
divi_link_assert( '#FFFFFF' === $links['title_text_color'] && '#000000' === $links['background_color'], 'does not treat Divi color values as language home links' );
divi_link_assert( '#top' === $links['anchor_link_url'], 'does not localize fragment-only anchors' );

echo "All SysOpenLang Divi home-link tests passed.\n";
