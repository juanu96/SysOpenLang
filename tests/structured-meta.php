<?php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ol_posts'] = array(
	1 => (object) array( 'ID' => 1, 'post_title' => 'Source' ),
	2 => (object) array( 'ID' => 2, 'post_title' => 'Target' ),
);
$GLOBALS['ol_meta'] = array(
	1 => array(
		'_builder_document' => array( wp_json_encode( array(
			array( 'id' => 'hero', 'type' => 'heading', 'settings' => array( 'title' => 'Hello world', 'color' => '#ffffff', 'url' => 'https://example.test/' ) ),
			array( 'id' => 'card', 'type' => 'text', 'settings' => array( 'content' => '<p>Readable body</p>', 'alignment' => 'center' ) ),
		) ) ),
		'headline'          => array( 'ACF headline' ),
		'_headline'         => array( 'field_123' ),
		'_builder_cache'    => array( '{"id":"cache","content":"Do not translate"}' ),
	),
	2 => array(),
);

function __( $text ) { return $text; }
function get_post( $post ) { return is_object( $post ) ? $post : ( $GLOBALS['ol_posts'][ $post ] ?? null ); }
function get_post_meta( $post_id, $key = '', $single = false ) {
	$meta = $GLOBALS['ol_meta'][ $post_id ] ?? array();
	if ( '' === $key ) { return $meta; }
	$values = $meta[ $key ] ?? array();
	return $single ? ( $values[0] ?? '' ) : $values;
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['ol_meta'][ $post_id ][ $key ] = array( $value ); }
function maybe_unserialize( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_kses_post( $value ) { return $value; }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function apply_filters( $hook, $value ) { return $value; }

require dirname( __DIR__ ) . '/src/contracts/interface-content-extractor.php';
require dirname( __DIR__ ) . '/src/class-structured-meta-content.php';

$extractor = new \SysOpenLang\Structured_Meta_Content();
$source = $GLOBALS['ol_posts'][1];
$target = $GLOBALS['ol_posts'][2];

if ( ! $extractor->supports( $source ) ) { throw new RuntimeException( 'Structured builder document was not detected.' ); }
$segments = $extractor->extract( $source );
if ( 2 !== count( $segments ) ) { throw new RuntimeException( 'Expected two human-readable segments; got ' . count( $segments ) . '.' ); }
$by_value = array();
foreach ( $segments as $segment ) { $by_value[ $segment['value'] ] = $segment['id']; }
if ( ! isset( $by_value['Hello world'], $by_value['<p>Readable body</p>'] ) ) { throw new RuntimeException( 'Expected text was not extracted.' ); }

$extractor->apply( $source, $target, array(
	$by_value['Hello world']          => 'Hola mundo',
	$by_value['<p>Readable body</p>'] => '<p>Cuerpo legible</p>',
) );
$saved = json_decode( $GLOBALS['ol_meta'][2]['_builder_document'][0], true );
if ( 'Hola mundo' !== $saved[0]['settings']['title'] || '<p>Cuerpo legible</p>' !== $saved[1]['settings']['content'] ) { throw new RuntimeException( 'Translations were not applied.' ); }
if ( '#ffffff' !== $saved[0]['settings']['color'] || 'center' !== $saved[1]['settings']['alignment'] ) { throw new RuntimeException( 'Technical values changed unexpectedly.' ); }

$GLOBALS['ol_meta'][1]['_builder_document'][0] = wp_json_encode( array_reverse( json_decode( $GLOBALS['ol_meta'][1]['_builder_document'][0], true ) ) );
$reordered = array();
foreach ( $extractor->extract( $source ) as $segment ) { $reordered[ $segment['value'] ] = $segment['id']; }
ksort( $by_value );
ksort( $reordered );
if ( $by_value !== $reordered ) { throw new RuntimeException( 'Stable node identities did not survive reordering.' ); }

$inspection = \SysOpenLang\Structured_Meta_Content::inspect( $source );
if ( 'translatable-document' !== $inspection['_builder_document'] || 'acf-field' !== $inspection['headline'] || 'excluded-key' !== $inspection['_builder_cache'] ) { throw new RuntimeException( 'Inspection reasons are incorrect.' ); }

echo "Structured meta extraction tests passed.\n";
