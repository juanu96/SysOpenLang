<?php
define( 'ABSPATH', __DIR__ . '/' );

$module_test_options = array(
	'openlingua_meta_policies' => array( 'page' => array( 'hero_title' => 'translate', 'shared_id' => 'copy' ) ),
);

function add_action() {}
function apply_filters( $hook, $value ) { return $value; }
function do_action() {}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function get_option( $key, $default = false ) { global $module_test_options; return $module_test_options[ $key ] ?? $default; }

require dirname( __DIR__ ) . '/src/contracts/interface-module.php';
require dirname( __DIR__ ) . '/src/contracts/interface-translation-provider.php';
require dirname( __DIR__ ) . '/src/class-module-registry.php';
require dirname( __DIR__ ) . '/src/modules/class-providers.php';
require dirname( __DIR__ ) . '/src/modules/class-metadata.php';

function module_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	echo "PASS: {$message}\n";
}

class Example_Module implements \SysOpenLang\Contracts\Module { public static $booted = false; public static function hooks() { self::$booted = true; } }
class Invalid_Module { public static function hooks() {} }
class Example_Provider implements \SysOpenLang\Contracts\Translation_Provider {
	public function id() { return 'example'; }
	public function label() { return 'Example'; }
	public function is_configured() { return true; }
	public function translate( array $segments, $source_language, $target_language, array $context = array() ) { return $segments; }
}

\SysOpenLang\Module_Registry::boot( array( Example_Module::class, Invalid_Module::class ) );
module_assert( Example_Module::$booted, 'boots valid modules' );
module_assert( 1 === count( \SysOpenLang\Module_Registry::all() ), 'rejects classes that do not implement the module contract' );
module_assert( \SysOpenLang\Modules\Providers::register( new Example_Provider() ), 'registers a valid translation provider' );
module_assert( null !== \SysOpenLang\Modules\Providers::get( 'example' ), 'retrieves a registered translation provider' );
module_assert( 'translate' === \SysOpenLang\Modules\Metadata::policy( 'hero_title', 'page' ), 'resolves a translatable custom-field policy' );
module_assert( 'copy' === \SysOpenLang\Modules\Metadata::policy( '_hero_title', 'page' ), 'copies the ACF field-key reference for a configured field' );
module_assert( 'ignore' === \SysOpenLang\Modules\Metadata::policy( '_openlingua_internal', 'page' ), 'protects internal metadata from duplication' );

echo "All SysOpenLang module tests passed.\n";
