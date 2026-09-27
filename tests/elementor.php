<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['elementor_meta'] = array();
	function __( $text ) { return $text; }
	function get_post( $post ) { return is_object( $post ) ? $post : (object) array( 'ID' => (int) $post ); }
	function get_post_meta( $post_id, $key ) { return $GLOBALS['elementor_meta'][ $post_id ][ $key ] ?? ''; }
	function update_post_meta( $post_id, $key, $value ) { $GLOBALS['elementor_meta'][ $post_id ][ $key ] = stripslashes( $value ); }
	function wp_unslash( $value ) { return stripslashes( $value ); }
	function wp_slash( $value ) { return addslashes( $value ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
	function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
	function wp_kses_post( $value ) { return preg_replace( '#<script[^>]*>.*?</script>#is', '', $value ); }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
	function apply_filters( $hook, $value ) { return $value; }
}

namespace SysOpenLang {
	require dirname( __DIR__ ) . '/src/contracts/interface-content-extractor.php';
	require dirname( __DIR__ ) . '/src/class-elementor-content.php';
	function elementor_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}.\n" ); exit( 1 ); } }

	$source = (object) array( 'ID' => 1 );
	$target = (object) array( 'ID' => 2 );
	$GLOBALS['elementor_meta'][1][Elementor_Content::DATA_META] = json_encode( array(
		array(
			'id' => 'abc123', 'elType' => 'widget', 'widgetType' => 'heading',
			'settings' => array( 'title' => '<h2>Hello world</h2>', 'title_color' => '#ffffff', 'link' => array( 'url' => 'https://example.test' ) ),
			'elements' => array(),
		),
		array(
			'id' => 'list123', 'elType' => 'widget', 'widgetType' => 'testimonial-carousel',
			'settings' => array( 'slides' => array( array( '_id' => 'stable-row', 'content' => 'First testimonial' ) ) ),
			'elements' => array(),
		),
	) );
	$extractor = new Elementor_Content();
	$segments = $extractor->extract( $source );
	elementor_assert( 2 === count( $segments ), 'extracts text while ignoring visual settings and URLs' );
	$translations = array();
	foreach ( $segments as $segment ) { $translations[ $segment['id'] ] = 'html' === $segment['format'] ? '<h2>Hola mundo</h2><script>bad()</script>' : 'Primer testimonio'; }
	$extractor->apply( $source, $target, $translations, 'es' );
	$stored = json_decode( $GLOBALS['elementor_meta'][2][Elementor_Content::DATA_META], true );
	elementor_assert( '<h2>Hola mundo</h2>' === $stored[0]['settings']['title'], 'updates and sanitizes rich text' );
	elementor_assert( '#ffffff' === $stored[0]['settings']['title_color'], 'preserves design settings' );
	elementor_assert( 'Primer testimonio' === $stored[1]['settings']['slides'][0]['content'], 'updates repeater text by stable row id' );
	echo "Elementor extractor tests passed.\n";
}
