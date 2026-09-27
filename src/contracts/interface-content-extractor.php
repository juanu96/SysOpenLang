<?php
namespace SysOpenLang\Contracts;

defined( 'ABSPATH' ) || exit;

/** Contract for builder-specific, translatable content stores. */
interface Content_Extractor {
	public function id();
	public function label();
	public function supports( $post );
	public function extract( $post );
	public function values( $post );
	public function apply( $source, $target, array $translations, $target_language = '' );
}
