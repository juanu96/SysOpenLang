<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Contracts\Translation_Provider;

defined( 'ABSPATH' ) || exit;

final class Anthropic_Provider implements Module, Translation_Provider {
	use Provider_Secrets;
	const OPTION = 'openlingua_anthropic_settings';
	public static function hooks() {
		add_filter( 'openlingua_translation_providers', array( __CLASS__, 'register' ) );
		add_action( 'openlingua_advanced_settings_sections', array( __CLASS__, 'settings_section' ) );
		add_action( 'admin_post_openlingua_save_anthropic', array( __CLASS__, 'save_settings' ) );
	}
	public static function register( $providers ) { $providers[] = new self(); return $providers; }
	public function id() { return 'anthropic'; }
	public function label() { return 'Claude'; }
	public function is_configured() { return '' !== self::api_key(); }
	public static function settings_url() { return add_query_arg( array( 'page' => 'openlingua-fields', 'section' => 'anthropic' ), admin_url( 'admin.php' ) ); }
	private static function settings() { return wp_parse_args( (array) get_option( self::OPTION, array() ), array( 'api_key' => '', 'model' => 'claude-sonnet-4-6' ) ); }
	private static function api_key() { return self::decrypt_secret( self::settings()['api_key'] ); }
	public static function settings_section() {
		$s = self::settings(); $configured = '' !== self::api_key(); $models = self::models();
		echo '<form id="openlingua-anthropic" class="openlingua-provider-panel" data-openlingua-provider-panel="anthropic" role="tabpanel" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_anthropic">'; wp_nonce_field( 'openlingua_save_anthropic' );
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Automatic translation with Claude', 'sysopenlang' ) . '</h2><p>' . esc_html__( 'Connect an Anthropic API key. Claude Code login or subscription credentials are not used by WordPress.', 'sysopenlang' ) . '</p>';
		Providers::setup_guide( array(
			array( 'text' => __( 'Sign in to the Anthropic Console or create an account.', 'sysopenlang' ), 'url' => 'https://console.anthropic.com/', 'label' => __( 'Anthropic Console', 'sysopenlang' ) ),
			array( 'text' => __( 'Add billing or usage credits if your account requires them.', 'sysopenlang' ), 'url' => 'https://console.anthropic.com/settings/billing', 'label' => __( 'Billing settings', 'sysopenlang' ) ),
			array( 'text' => __( 'Open API keys and create a key.', 'sysopenlang' ), 'url' => 'https://console.anthropic.com/settings/keys', 'label' => __( 'API keys', 'sysopenlang' ) ),
			array( 'text' => __( 'Copy the key and paste it below.', 'sysopenlang' ) ),
		), __( 'Claude Code and Claude subscriptions do not provide an API key to WordPress.', 'sysopenlang' ) );
		echo '<table class="form-table" role="presentation"><tr><th><label for="openlingua-anthropic-key">' . esc_html__( 'Anthropic API key', 'sysopenlang' ) . '</label></th><td><input id="openlingua-anthropic-key" class="regular-text" type="password" name="api_key" autocomplete="new-password" placeholder="sk-ant-…">';
		if ( $configured ) { echo '<p class="description"><strong>' . esc_html__( 'A key is configured.', 'sysopenlang' ) . '</strong> ' . esc_html__( 'Leave empty to keep it.', 'sysopenlang' ) . '</p><label><input type="checkbox" name="clear_api_key" value="1"> ' . esc_html__( 'Remove the saved key', 'sysopenlang' ) . '</label>'; }
		echo '</td></tr><tr><th><label for="openlingua-anthropic-model">' . esc_html__( 'Model', 'sysopenlang' ) . '</label></th><td><select id="openlingua-anthropic-model" name="model">'; foreach ( $models as $id => $label ) { echo '<option value="' . esc_attr( $id ) . '"' . selected( $s['model'], $id, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></td></tr><tr><th>' . esc_html__( 'Active provider', 'sysopenlang' ) . '</th><td><label><input type="radio" name="activate_provider" value="1"' . checked( Providers::active_id(), 'anthropic', false ) . '> ' . esc_html__( 'Use Claude for automatic translations', 'sysopenlang' ) . '</label></td></tr></table>'; submit_button( __( 'Save Claude settings', 'sysopenlang' ) ); echo '</section></form>';
	}
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); } check_admin_referer( 'openlingua_save_anthropic' );
		$s = self::settings(); $encrypted = ! empty( $_POST['clear_api_key'] ) ? '' : $s['api_key']; $key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( '' !== $key ) { $encrypted = self::encrypt_secret( $key ); }
		update_option( self::OPTION, array( 'api_key' => $encrypted, 'model' => sanitize_text_field( wp_unslash( $_POST['model'] ?? 'claude-sonnet-4-6' ) ) ), false ); if ( $encrypted && ! empty( $_POST['activate_provider'] ) ) { Providers::activate( 'anthropic' ); } delete_transient( 'openlingua_anthropic_models' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-fields', 'section' => 'anthropic' ), admin_url( 'admin.php' ) ) ); exit;
	}
	private static function models() {
		$fallback = array( 'claude-sonnet-4-6' => 'Claude Sonnet 4.6', 'claude-haiku-4-5' => 'Claude Haiku 4.5', 'claude-opus-4-6' => 'Claude Opus 4.6' ); $current = self::settings()['model'];
		$cached = get_transient( 'openlingua_anthropic_models' ); if ( is_array( $cached ) ) { return array( $current => $cached[ $current ] ?? $current ) + $cached; }
		if ( ! self::api_key() ) { return array( $current => $fallback[ $current ] ?? $current ) + $fallback; }
		$r = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=100', array( 'timeout' => 5, 'headers' => array( 'x-api-key' => self::api_key(), 'anthropic-version' => '2023-06-01' ) ) );
		if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) { set_transient( 'openlingua_anthropic_models', $fallback, HOUR_IN_SECONDS ); return $fallback; }
		$body = json_decode( wp_remote_retrieve_body( $r ), true ); $models = array(); foreach ( (array) ( $body['data'] ?? array() ) as $m ) { if ( ! empty( $m['id'] ) ) { $models[ $m['id'] ] = $m['display_name'] ?? $m['id']; } }
		if ( ! $models ) { $models = $fallback; } set_transient( 'openlingua_anthropic_models', $models, 12 * HOUR_IN_SECONDS ); return array( $current => $models[ $current ] ?? $current ) + $models;
	}
	public function translate( array $segments, $source_language, $target_language, array $context = array() ) {
		$key = self::api_key(); if ( ! $key ) { return new \WP_Error( 'openlingua_anthropic_key', __( 'The Anthropic API key is not configured.', 'sysopenlang' ) ); }
		$segments = array_filter( array_map( 'strval', $segments ), static function( $v ) { return '' !== trim( $v ); } ); $translated = array();
		$batch_size = max( 5, absint( Site_Settings::get()['batch_size'] ) );
		foreach ( array_chunk( $segments, $batch_size, true ) as $batch ) {
			$payload = array( 'model' => self::settings()['model'], 'max_tokens' => 16384, 'system' => 'You are a professional website translator. Output valid JSON only.', 'messages' => array( array( 'role' => 'user', 'content' => self::translation_prompt( $batch, $source_language, $target_language ) ) ) );
			$r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array( 'timeout' => 120, 'headers' => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) ); if ( is_wp_error( $r ) ) { return $r; }
			$body = json_decode( wp_remote_retrieve_body( $r ), true ); $status = wp_remote_retrieve_response_code( $r ); if ( $status < 200 || $status >= 300 ) { return new \WP_Error( 'openlingua_anthropic_api', sanitize_text_field( $body['error']['message'] ?? 'Anthropic API error ' . $status ) ); }
			$text = ''; foreach ( (array) ( $body['content'] ?? array() ) as $block ) { if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= $block['text'] ?? ''; } } $result = self::json_result( $text, $batch, 'anthropic' ); if ( is_wp_error( $result ) ) { return $result; } $translated = array_merge( $translated, $result );
		}
		return $translated;
	}
}
