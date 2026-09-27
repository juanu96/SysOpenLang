<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Languages;
use SysOpenLang\Strings;

defined( 'ABSPATH' ) || exit;

final class String_Discovery implements Module {
	private static $seen = array();
	private static $running = false;

	public static function hooks() {
		add_filter( 'gettext', array( __CLASS__, 'gettext' ), 999, 3 );
		add_filter( 'gettext_with_context', array( __CLASS__, 'gettext_with_context' ), 999, 4 );
		add_filter( 'ngettext', array( __CLASS__, 'ngettext' ), 999, 5 );
		add_filter( 'ngettext_with_context', array( __CLASS__, 'ngettext_with_context' ), 999, 6 );
	}

	public static function gettext( $translated, $original, $domain ) {
		return self::translate( $translated, $original, '', $domain );
	}

	public static function gettext_with_context( $translated, $original, $context, $domain ) {
		return self::translate( $translated, $original, (string) $context, $domain );
	}

	public static function ngettext( $translated, $single, $plural, $number, $domain ) {
		$original = 1 === absint( $number ) ? $single : $plural;
		return self::translate( $translated, $original, 'plural', $domain );
	}

	public static function ngettext_with_context( $translated, $single, $plural, $number, $context, $domain ) {
		$original = 1 === absint( $number ) ? $single : $plural;
		return self::translate( $translated, $original, 'plural:' . (string) $context, $domain );
	}

	private static function translate( $translated, $original, $context, $domain ) {
		if ( self::$running || '' === trim( (string) $original ) ) { return $translated; }
		$domain = sanitize_key( $domain ?: 'default' );
		$key = self::key( $domain, $original, $context );
		$seen_key = $domain . ':' . $key;
		self::$running = true;
		$discovering = (bool) get_option( 'openlingua_string_discovery', false );
		$until = absint( get_option( 'openlingua_string_discovery_until', 0 ) );
		$minutes = class_exists( __NAMESPACE__ . '\\Site_Settings' ) ? absint( Site_Settings::get()['discovery_minutes'] ) : 10;
		if ( $discovering && ! $until ) {
			$until = time() + max( 1, $minutes ) * 60;
			if ( function_exists( 'update_option' ) ) { update_option( 'openlingua_string_discovery_until', $until, false ); }
		}
		if ( $discovering && $until < time() ) {
			if ( function_exists( 'update_option' ) ) { update_option( 'openlingua_string_discovery', false ); }
			if ( function_exists( 'delete_option' ) ) { delete_option( 'openlingua_string_discovery_until' ); }
			$discovering = false;
		}
		if ( $discovering && ! isset( self::$seen[ $seen_key ] ) ) {
			self::$seen[ $seen_key ] = true;
			Strings::register( $key, $original, $domain, Languages::default_code() );
		}
		$value = Strings::translate( $key, $translated, $domain, Languages::current(), false );
		self::$running = false;
		return $value;
	}

	private static function key( $domain, $original, $context = '' ) {
		$material = $domain . "\0" . ( '' === $context ? '' : $context . "\0" ) . $original;
		return substr( hash( 'sha256', $material ), 0, 32 );
	}
}
