<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $text ) { return $text; }
	function apply_filters( $hook, $value ) { return $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function get_post_type( $post_id ) { return in_array( $post_id, array( 20, 40 ), true ) ? 'product' : 'post'; }
	function taxonomy_exists( $taxonomy ) { return 'pa_color' === $taxonomy; }
	function get_term_by( $field, $value, $taxonomy ) { return 'slug' === $field && 'pa_color' === $taxonomy && 'red' === $value ? (object) array( 'term_id' => 100, 'slug' => 'red' ) : false; }
	function get_term( $term_id, $taxonomy ) { return 200 === $term_id && 'pa_color' === $taxonomy ? (object) array( 'term_id' => 200, 'slug' => 'rojo' ) : false; }
	function is_wp_error() { return false; }
	$GLOBALS['openlingua_wc_products'] = array();
	$GLOBALS['openlingua_wc_meta'] = array();
	function wc_get_product( $product_id ) { return $GLOBALS['openlingua_wc_products'][ $product_id ] ?? false; }
	function get_post_meta( $post_id, $key ) { return $GLOBALS['openlingua_wc_meta'][ $post_id ][ $key ] ?? ''; }
	function wp_kses_post( $value ) { return preg_replace( '#<script[^>]*>.*?</script>#is', '', $value ); }
	function do_action() {}
	class WC_Product_Variation {
		public static $created;
		public $data = array();
		public function __construct() { self::$created = $this; }
		public function __call( $method, $arguments ) {
			if ( 0 === strpos( $method, 'set_' ) ) { $this->data[ substr( $method, 4 ) ] = $arguments[0]; return; }
			if ( 0 === strpos( $method, 'get_' ) ) { return $this->data[ substr( $method, 4 ) ] ?? ''; }
		}
		public function update_meta_data( $key, $value ) { $this->data['meta'][ $key ] = $value; }
		public function save() { $this->data['saved'] = true; return 901; }
	}
}

namespace SysOpenLang {
	final class Translations {
		public static function translated_id( $type, $id, $language ) {
			$map = array( 10 => 20, 30 => 40, 50 => 60 );
			if ( 'term' === $type && 100 === $id && 'es' === $language ) { return 200; }
			return 'post' === $type && 'es' === $language ? ( $map[ $id ] ?? 0 ) : 0;
		}
	}
}

namespace SysOpenLang\Modules {
	require dirname( __DIR__ ) . '/src/contracts/interface-module.php';
	require dirname( __DIR__ ) . '/src/modules/class-commerce.php';
	function commerce_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}.\n" ); exit( 1 ); } }
	final class Commerce_Test_Attribute {
		private $taxonomy;
		private $options;
		public function __construct( $taxonomy, array $options ) { $this->taxonomy = $taxonomy; $this->options = $options; }
		public function is_taxonomy() { return $this->taxonomy; }
		public function get_options() { return $this->options; }
		public function set_options( array $options ) { $this->options = $options; }
	}
	final class Commerce_Test_Product {
		private $id;
		private $type;
		private $children;
		public $data;
		public function __construct( $id, $type, array $children = array(), array $data = array() ) { $this->id = $id; $this->type = $type; $this->children = $children; $this->data = $data; }
		public function get_id() { return $this->id; }
		public function get_children() { return $this->children; }
		public function is_type( $type ) { return $this->type === $type; }
		public function get_attributes() { return $this->data['attributes'] ?? array(); }
		public function __call( $method, $arguments ) {
			if ( 0 === strpos( $method, 'get_' ) ) { return $this->data[ substr( $method, 4 ) ] ?? ''; }
			if ( 0 === strpos( $method, 'set_' ) ) { $this->data[ substr( $method, 4 ) ] = $arguments[0]; return; }
			if ( 'save' === $method ) { $this->data['saved'] = true; return $this->id; }
		}
	}
	$keys = Commerce::shared_keys();
	commerce_assert( in_array( '_downloadable_files', $keys, true ) && in_array( '_weight', $keys, true ), 'shares operational download and dimension data' );
	commerce_assert( array( 20, 40 ) === Commerce::translated_product_ids( array( 10, 30, 50, 10 ), 'es' ), 'maps related products to available target-language products only' );
	$source_attribute = new Commerce_Test_Attribute( true, array( 100 ) );
	$translated_attributes = Commerce::translated_attributes( array( 'pa_color' => $source_attribute ), 'es' );
	commerce_assert( array( 200 ) === $translated_attributes['pa_color']->get_options(), 'maps global product attribute terms to the target language' );
	commerce_assert( array( 100 ) === $source_attribute->get_options(), 'does not mutate the source product attribute object' );
	commerce_assert( array( 'pa_color' => 'rojo', 'custom' => 'Large' ) === Commerce::translated_variation_attributes( array( 'pa_color' => 'red', 'custom' => 'Large' ), 'es' ), 'maps taxonomy variation slugs and preserves local attributes' );
	$source_variation = new Commerce_Test_Product( 501, 'variation', array(), array( 'attributes' => array( 'pa_color' => 'red' ), 'regular_price' => '25', 'stock_quantity' => 3 ) );
	$GLOBALS['openlingua_wc_products'][501] = $source_variation;
	$source_product = new Commerce_Test_Product( 50, 'variable', array( 501 ) );
	$target_product = new Commerce_Test_Product( 60, 'variable' );
	$method = new \ReflectionMethod( Commerce::class, 'synchronize_variations' );
	$method->setAccessible( true );
	$method->invoke( null, $source_product, $target_product, 'es' );
	$created = \WC_Product_Variation::$created;
	commerce_assert( 60 === $created->data['parent_id'] && '25' === $created->data['regular_price'], 'creates a target variation under the translated product and copies operational data' );
	commerce_assert( array( 'pa_color' => 'rojo' ) === $created->data['attributes'], 'uses translated taxonomy slugs in the target variation' );
	commerce_assert( 501 === $created->data['meta'][ Commerce::SOURCE_VARIATION_META ] && ! empty( $created->data['saved'] ), 'stores a stable source-variation relationship before saving' );
	$target_variation = new Commerce_Test_Product( 601, 'variation', array(), array( 'description' => 'Descripción anterior' ) );
	$GLOBALS['openlingua_wc_products'] += array( 50 => $source_product, 60 => new Commerce_Test_Product( 60, 'variable', array( 601 ) ), 601 => $target_variation );
	$GLOBALS['openlingua_wc_meta'][601][ Commerce::SOURCE_VARIATION_META ] = 501;
	$source_variation_for_text = new Commerce_Test_Product( 501, 'variation', array(), array( 'description' => '<p>Source variation</p>', 'name' => 'Red' ) );
	$GLOBALS['openlingua_wc_products'][501] = $source_variation_for_text;
	$fields = Commerce::translation_fields( 50, 60 );
	commerce_assert( 1 === count( $fields ) && '<p>Source variation</p>' === $fields[0]['source'], 'exposes linked variation descriptions for translation' );
	Commerce::save_translation_fields( 50, 60, array( $fields[0]['id'] => '<p>Descripción nueva</p><script>bad()</script>' ) );
	commerce_assert( '<p>Descripción nueva</p>' === $target_variation->data['description'] && ! empty( $target_variation->data['saved'] ), 'saves and sanitizes the translated variation description' );
	echo "WooCommerce integration tests passed.\n";
}
