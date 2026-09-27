<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['seo_meta'] = array(
		10 => array( '_yoast_wpseo_title' => 'Original SEO title', '_yoast_wpseo_metadesc' => 'Original description' ),
		20 => array( '_yoast_wpseo_title' => '', '_yoast_wpseo_metadesc' => '' ),
	);
	$GLOBALS['seo_term_meta'] = array(
		100 => array( '_yoast_wpseo_title' => 'Original term SEO title', '_yoast_wpseo_metadesc' => 'Original term description' ),
		200 => array( '_yoast_wpseo_title' => '', '_yoast_wpseo_metadesc' => '' ),
	);
	function __( $text ) { return $text; }
	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) { return $value; }
	function get_post_meta( $post_id, $key ) { return $GLOBALS['seo_meta'][ $post_id ][ $key ] ?? ''; }
	function update_post_meta( $post_id, $key, $value ) { $GLOBALS['seo_meta'][ $post_id ][ $key ] = $value; }
	function get_term_meta( $term_id, $key ) { return $GLOBALS['seo_term_meta'][ $term_id ][ $key ] ?? ''; }
	function update_term_meta( $term_id, $key, $value ) { $GLOBALS['seo_term_meta'][ $term_id ][ $key ] = $value; }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
	function do_action() {}
}

namespace SysOpenLang {
	final class SEO_Test_DB {
		public $prefix = 'wp_';
		public function prepare( $query ) { return $query; }
		public function get_var() { return null; }
	}
	$GLOBALS['wpdb'] = new SEO_Test_DB();
	require dirname( __DIR__ ) . '/src/class-seo.php';

	$groups = SEO::translation_fields( 10, 20 );
	if ( empty( $groups['yoast']['fields'] ) || 2 !== count( $groups['yoast']['fields'] ) ) { fwrite( STDERR, "FAIL: Yoast fields were not detected.\n" ); exit( 1 ); }
	$title = $groups['yoast']['fields'][0];
	SEO::save_translation_fields( 10, 20, array( $title['id'] => 'Título SEO traducido' ) );
	if ( 'Título SEO traducido' !== $GLOBALS['seo_meta'][20]['_yoast_wpseo_title'] ) { fwrite( STDERR, "FAIL: Yoast translation was not saved.\n" ); exit( 1 ); }
	$term_groups = SEO::term_translation_fields( 100, 200 );
	if ( empty( $term_groups['yoast']['fields'] ) || 2 !== count( $term_groups['yoast']['fields'] ) ) { fwrite( STDERR, "FAIL: Yoast term fields were not detected.\n" ); exit( 1 ); }
	$term_title = $term_groups['yoast']['fields'][0];
	SEO::save_term_translation_fields( 100, 200, array( $term_title['id'] => 'Título SEO del término' ) );
	if ( 'Título SEO del término' !== $GLOBALS['seo_term_meta'][200]['_yoast_wpseo_title'] ) { fwrite( STDERR, "FAIL: Yoast term translation was not saved.\n" ); exit( 1 ); }
	echo "SEO translation tests passed.\n";
}
