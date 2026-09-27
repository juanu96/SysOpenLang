<?php
namespace SysOpenLang\Contracts;

defined( 'ABSPATH' ) || exit;

interface Translation_Provider {
	public function id();
	public function label();
	public function is_configured();
	public function translate( array $segments, $source_language, $target_language, array $context = array() );
}

