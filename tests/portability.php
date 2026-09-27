<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $text ) { return $text; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function get_post( $id ) { return 10 === $id ? (object) array( 'ID' => 10 ) : null; }
	function get_term( $id ) { return 20 === $id ? (object) array( 'term_id' => 20 ) : null; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error {
		private $message;
		public function __construct( $code, $message ) { unset( $code ); $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
}

namespace SysOpenLang {
	final class Translations {
		public static function row( $type, $id ) {
			return 'post' === $type && 10 === $id ? (object) array( 'language' => 'en', 'group_uuid' => 'existing-group' ) : null;
		}
	}
}

namespace SysOpenLang\Modules {
	require dirname( __DIR__ ) . '/src/contracts/interface-module.php';
	require dirname( __DIR__ ) . '/src/modules/class-portability.php';
	$data = array(
		'format' => 'openlingua-portable', 'format_version' => 1,
		'translations' => array(
			array( 'element_type' => 'post', 'element_id' => 10, 'language' => 'es', 'group_uuid' => 'new-group' ),
			array( 'element_type' => 'term', 'element_id' => 20, 'language' => 'es', 'group_uuid' => 'term-group' ),
			array( 'element_type' => 'post', 'element_id' => 999, 'language' => 'es', 'group_uuid' => 'missing' ),
			array( 'element_type' => 'invalid', 'element_id' => 5, 'language' => '?', 'group_uuid' => '' ),
		),
		'strings' => array(
			array( 'string_key' => 'hello', 'translations' => '{"es":"Hola"}' ),
			array( 'string_key' => '', 'translations' => 'invalid' ),
		),
	);
	$report = Portability::analyze( $data );
	if ( 2 !== $report['relationships'] || 1 !== $report['strings'] || 1 !== $report['missing_content'] || 2 !== $report['invalid_rows'] || 1 !== $report['conflicts'] ) { fwrite( STDERR, "FAIL: import preview report is incorrect.\n" ); exit( 1 ); }
	if ( ! \is_wp_error( Portability::analyze( array() ) ) ) { fwrite( STDERR, "FAIL: invalid import format was accepted.\n" ); exit( 1 ); }
	echo "Portability preview tests passed.\n";
}
