<?php
// Lightweight WPGraphQL compatibility tests without booting WordPress.
define( 'ABSPATH', __DIR__ . '/' );

$openlingua_test_options = array(
	'openlingua_languages' => array( 'en' => array( 'name' => 'English' ), 'es' => array( 'name' => 'Español' ) ),
	'openlingua_default_language' => 'en',
);

function get_option( $key, $default = false ) { global $openlingua_test_options; return $openlingua_test_options[ $key ] ?? $default; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $text ) { return $text; }
function wp_unslash( $value ) { return stripslashes( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return (string) $url; }
function add_action() {}
function add_filter() {}
function get_post_types() { return array( 'listing' => (object) array( 'name' => 'listing', 'graphql_single_name' => 'listing' ) ); }
function get_taxonomies() { return array( 'status_listing' => (object) array( 'graphql_single_name' => 'stateListing', 'object_type' => array( 'listing' ) ) ); }
function register_graphql_field( $type, $field, $config ) { global $openlingua_graphql_registered_fields; $openlingua_graphql_registered_fields[] = compact( 'type', 'field', 'config' ); }
function register_graphql_connection_where_arg( $from, $to, $field, $config ) { global $openlingua_graphql_registered_connections; $openlingua_graphql_registered_connections[] = compact( 'from', 'to', 'field', 'config' ); }
function get_the_title( $post_id ) { return 22 === (int) $post_id ? 'Vista del Valle' : ''; }

require dirname( __DIR__ ) . '/src/class-languages.php';
require dirname( __DIR__ ) . '/src/class-graphql.php';

function openlingua_graphql_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) { fwrite( STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: {$actual}\n" ); exit( 1 ); }
	echo "PASS: {$message}\n";
}

\OpenLingua\GraphQL::register_language_arguments();
openlingua_graphql_assert_same( 'openlinguaLanguage', $openlingua_graphql_registered_fields[0]['field'], 'exposes the language argument on root GraphQL connections' );
openlingua_graphql_assert_same( 'Listing', $openlingua_graphql_registered_connections[0]['from'], 'exposes the language argument on CPT taxonomy connections' );
openlingua_graphql_assert_same( 'StateListing', $openlingua_graphql_registered_connections[0]['to'], 'targets the connected custom taxonomy in GraphQL' );

$_GET['lang'] = 'es';
openlingua_graphql_assert_same( 'es', \OpenLingua\GraphQL::request_language(), 'accepts the GraphQL lang query parameter' );
unset( $_GET['lang'] );

$_SERVER['HTTP_X_OPENLINGUA_LANGUAGE'] = 'es';
openlingua_graphql_assert_same( 'es', \OpenLingua\GraphQL::request_language(), 'accepts the GraphQL language header' );
unset( $_SERVER['HTTP_X_OPENLINGUA_LANGUAGE'] );

$_SERVER['HTTP_REFERER'] = 'https://example.test/es/listings/';
openlingua_graphql_assert_same( 'es', \OpenLingua\GraphQL::request_language(), 'detects the language from a browser referrer' );

$query_args = \OpenLingua\GraphQL::filter_post_query_args( array( 'post_type' => 'listing' ), null, array( 'where' => array( 'openlinguaLanguage' => 'es' ) ), null, null );
openlingua_graphql_assert_same( 'es', $query_args['openlingua_language'], 'marks WPGraphQL post queries with the requested language' );
openlingua_graphql_assert_same( true, $query_args['openlingua_source_order'], 'keeps the default listing order aligned with the source language' );

$ordered_query_args = \OpenLingua\GraphQL::filter_post_query_args( array( 'post_type' => 'listing' ), null, array( 'where' => array( 'openlinguaLanguage' => 'es', 'orderby' => 'TITLE' ) ), null, null );
openlingua_graphql_assert_same( false, isset( $ordered_query_args['openlingua_source_order'] ), 'preserves an explicit client ordering' );

$term_query_args = \OpenLingua\GraphQL::filter_term_query_args( array(), null, array( 'where' => array( 'openlinguaLanguage' => 'es' ) ), null, null );
openlingua_graphql_assert_same( 'es', $term_query_args['openlingua_language'], 'marks WPGraphQL taxonomy queries with the requested language' );

$connected_term_query_args = \OpenLingua\GraphQL::filter_term_query_args( array(), (object) array( 'databaseId' => 27564740 ), array( 'where' => array( 'openlinguaLanguage' => 'es' ) ), null, null );
openlingua_graphql_assert_same( true, $connected_term_query_args['openlingua_skip_language_filter'], 'keeps every physical custom-taxonomy term assigned to a connected translated post' );

$deferred_connected_term_query_args = \OpenLingua\GraphQL::filter_term_query_args( array(), null, array( 'where' => array( 'openlinguaLanguage' => 'es' ) ), null, (object) array( 'parentType' => (object) array( 'name' => 'Listing' ) ) );
openlingua_graphql_assert_same( true, $deferred_connected_term_query_args['suppress_filter'], 'keeps connected taxonomy terms when WPGraphQL defers the parent post model' );

$empty_property_id = \OpenLingua\GraphQL::fallback_empty_property_id( null, (object) array( 'databaseId' => 22 ), array(), null, null, 'ContentListingOverviewPropertyId', 'content', null, null );
openlingua_graphql_assert_same( 'Vista del Valle', $empty_property_id, 'falls back to the translated post title for an empty ACF property name' );
$defined_property_id = \OpenLingua\GraphQL::fallback_empty_property_id( 'Nombre personalizado', (object) array( 'databaseId' => 22 ), array(), null, null, 'ContentListingOverviewPropertyId', 'content', null, null );
openlingua_graphql_assert_same( 'Nombre personalizado', $defined_property_id, 'keeps an explicitly translated ACF property name' );
$deferred_property_id = new stdClass();
openlingua_graphql_assert_same( $deferred_property_id, \OpenLingua\GraphQL::fallback_empty_property_id( $deferred_property_id, (object) array( 'databaseId' => 22 ), array(), null, null, 'ContentListingOverviewPropertyId', 'content', null, null ), 'keeps deferred WPGraphQL field results untouched' );

$media_query_args = \OpenLingua\GraphQL::filter_post_query_args( array( 'post_type' => 'attachment' ), null, array(), null, null );
openlingua_graphql_assert_same( false, isset( $media_query_args['openlingua_language'] ), 'keeps shared WPGraphQL media available in every language' );

echo "All OpenLingua WPGraphQL tests passed.\n";
