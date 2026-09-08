<?php
namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_CLI {
		public static $lines = array();
		public static function add_command() {}
		public static function line( $message ) { self::$lines[] = $message; }
		public static function error( $message ) { throw new RuntimeException( $message ); }
	}

	function __( $text ) { return $text; }
	function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }

	require dirname( __DIR__ ) . '/src/contracts/interface-module.php';
}

namespace OpenLingua\Modules {
	final class Portability {
		public static function snapshot() { return array( 'format' => 'openlingua-portable', 'format_version' => 1 ); }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/modules/class-cli.php';

	function cli_assert( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
		echo "PASS: {$message}\n";
	}

	$command = new \OpenLingua\Modules\CLI_Command();
	$command->export( array(), array() );
	cli_assert( 1 === count( \WP_CLI::$lines ), 'writes one machine-readable export to standard output' );
	cli_assert( array( 'format' => 'openlingua-portable', 'format_version' => 1 ) === json_decode( \WP_CLI::$lines[0], true ), 'outputs a portable JSON snapshot' );

	try {
		$command->export( array(), array( 'file' => 'outside-the-site.json' ) );
		cli_assert( false, 'rejects the retired arbitrary --file path option' );
	} catch ( \RuntimeException $error ) {
		cli_assert( false !== strpos( $error->getMessage(), '--file option is no longer supported' ), 'rejects the retired arbitrary --file path option' );
	}
}
