<?php
namespace SysOpenLang;

use SysOpenLang\Contracts\Module;

defined( 'ABSPATH' ) || exit;

final class Module_Registry {
	private static $modules = array();

	public static function boot( array $modules ) {
		foreach ( $modules as $module ) {
			if ( ! is_subclass_of( $module, Module::class ) ) {
				do_action( 'openlingua_invalid_module', $module );
				continue;
			}
			$module::hooks();
			self::$modules[] = $module;
		}
		do_action( 'openlingua_modules_loaded', self::$modules );
	}

	public static function all() {
		return self::$modules;
	}
}

