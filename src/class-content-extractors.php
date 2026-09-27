<?php
namespace SysOpenLang;

use SysOpenLang\Contracts\Content_Extractor;

defined( 'ABSPATH' ) || exit;

/** Public registry for builder and plugin content extractors. */
final class Content_Extractors {
	private static $extractors = array();

	public static function hooks() {
		self::register( new Elementor_Content() );
		self::register( new Structured_Meta_Content() );
		do_action( 'openlingua_register_content_extractors', __CLASS__ );
	}

	public static function register( Content_Extractor $extractor ) {
		self::$extractors[ sanitize_key( $extractor->id() ) ] = $extractor;
	}

	public static function all() {
		return (array) apply_filters( 'openlingua_content_extractors', self::$extractors );
	}

	public static function for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) { return null; }
		foreach ( self::all() as $extractor ) {
			if ( $extractor instanceof Content_Extractor && $extractor->supports( $post ) ) { return $extractor; }
		}
		return null;
	}
}
