<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function add_filter() {}
	function apply_filters( $hook, $value ) { return $value; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
}

namespace OpenLingua {
	$GLOBALS['shortcode_tags'] = array(
		'sample_widget' => '__return_empty_string',
		'revslider_divi' => '__return_empty_string',
	);

	final class Languages {
		public static function current() { return 'es'; }
		public static function default_code() { return 'en'; }
	}

	final class Strings {
		public static $seen = array();
		public static function translate( $key, $fallback, $domain ) {
			self::$seen[ $domain . ':' . $key ] = $fallback;
			$translations = array(
				'Widget title' => 'Título del widget',
				'Action label' => 'Etiqueta de acción',
				'Search content' => 'Buscar contenido',
			);
			return $translations[ $fallback ] ?? $fallback;
		}
	}

	require dirname( __DIR__ ) . '/src/class-shortcode-content.php';

	$html = '<div class="sample-widget">'
		. '<div class="sample-widget__item"><h4 class="sample-widget__label">Widget title</h4><div class="sample-widget__value">Dynamic value 123</div></div>'
		. '<div class="sample-widget__item"><label>Action label</label><div class="sample-widget__value">42</div></div>'
		. '<input placeholder="Search content" title="Search content"><img alt="" src="icon.svg">'
		. '<script>const example = \'title="Search content"\';</script>'
		. '</div>';
	$output = Shortcode_Content::translate_output( $html, 'sample_widget' );
	$divi_output = Shortcode_Content::translate_output( '<h4>Divi wrapper label</h4>', 'et_pb_column' );
	$slider_output = Shortcode_Content::translate_output( '<div class="rev_slider"><rs-module><rs-slide><rs-layer>Discover our services</rs-layer></rs-slide></rs-module></div>', 'revslider_divi' );

	$checks = array(
		'heading label translated' => false !== strpos( $output, '>Título del widget</h4>' ),
		'second label translated' => false !== strpos( $output, '>Etiqueta de acción</label>' ),
		'dynamic text preserved' => false !== strpos( $output, '>Dynamic value 123</div>' ),
		'dynamic count preserved' => false !== strpos( $output, '>42</div>' ),
		'placeholder translated' => false !== strpos( $output, 'placeholder="Buscar contenido"' ),
		'title translated' => false !== strpos( $output, 'title="Buscar contenido"' ),
		'empty alt preserved' => false !== strpos( $output, 'alt=""' ),
		'script content preserved' => false !== strpos( $output, 'const example = \'title="Search content"\'' ),
		'dynamic root identified' => false !== strpos( $output, 'data-openlingua-shortcode="sample_widget"' ),
		'dynamic root remains visible while unknown strings are discovered' => false === strpos( $output, 'openlingua-shortcode-pending' ),
		'domain grouped by shortcode' => 0 < count( array_filter( array_keys( Strings::$seen ), static function ( $key ) { return 0 === strpos( $key, 'shortcode-sample_widget:' ); } ) ),
		'divi wrappers ignored' => '<h4>Divi wrapper label</h4>' === $divi_output && ! in_array( 'Divi wrapper label', Strings::$seen, true ),
		'slider revolution output marked for dynamic text discovery' => false !== strpos( $slider_output, 'data-openlingua-shortcode="revslider_divi"' ),
	);

	foreach ( $checks as $label => $passed ) {
		if ( ! $passed ) { fwrite( STDERR, "FAIL: {$label}\n{$output}\n" ); exit( 1 ); }
	}
	echo "Shortcode extraction tests passed.\n";
}
