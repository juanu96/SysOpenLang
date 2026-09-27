<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Contracts\Translation_Provider;

defined( 'ABSPATH' ) || exit;

final class Gemini_Provider implements Module, Translation_Provider {
	use Provider_Secrets;
	const OPTION = 'openlingua_gemini_settings';
	public static function hooks() { add_filter( 'openlingua_translation_providers', array( __CLASS__, 'register' ) ); add_action( 'openlingua_advanced_settings_sections', array( __CLASS__, 'settings_section' ) ); add_action( 'admin_post_openlingua_save_gemini', array( __CLASS__, 'save_settings' ) ); }
	public static function register( $providers ) { $providers[] = new self(); return $providers; }
	public function id() { return 'gemini'; } public function label() { return 'Gemini'; } public function is_configured() { return '' !== self::api_key(); }
	public static function settings_url() { return add_query_arg( array( 'page' => 'openlingua-fields', 'section' => 'gemini' ), admin_url( 'admin.php' ) ); }
	private static function settings() { return wp_parse_args( (array) get_option( self::OPTION, array() ), array( 'api_key' => '', 'model' => 'gemini-3.5-flash-lite' ) ); }
	private static function api_key() { return self::decrypt_secret( self::settings()['api_key'] ); }
	public static function settings_section() {
		$s = self::settings(); $configured = '' !== self::api_key(); $models = self::models();
		echo '<form id="openlingua-gemini" class="openlingua-provider-panel" data-openlingua-provider-panel="gemini" role="tabpanel" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_gemini">'; wp_nonce_field( 'openlingua_save_gemini' );
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Automatic translation with Gemini', 'sysopenlang' ) . '</h2><p>' . esc_html__( 'Connect your Google AI Studio Gemini API key.', 'sysopenlang' ) . '</p>';
		Providers::setup_guide( array(
			array( 'text' => __( 'Sign in to Google AI Studio with your Google account.', 'sysopenlang' ), 'url' => 'https://aistudio.google.com/', 'label' => __( 'Google AI Studio', 'sysopenlang' ) ),
			array( 'text' => __( 'Accept the terms and select or import a Google Cloud project.', 'sysopenlang' ) ),
			array( 'text' => __( 'Create a Gemini API key for that project.', 'sysopenlang' ), 'url' => 'https://aistudio.google.com/apikey', 'label' => __( 'Create API key', 'sysopenlang' ) ),
			array( 'text' => __( 'Copy the key and paste it below.', 'sysopenlang' ) ),
		), __( 'Availability and free usage limits depend on the selected model, project, and region.', 'sysopenlang' ) );
		echo '<table class="form-table" role="presentation"><tr><th><label for="openlingua-gemini-key">' . esc_html__( 'Gemini API key', 'sysopenlang' ) . '</label></th><td><input id="openlingua-gemini-key" class="regular-text" type="password" name="api_key" autocomplete="new-password">';
		if ( $configured ) { echo '<p class="description"><strong>' . esc_html__( 'A key is configured.', 'sysopenlang' ) . '</strong> ' . esc_html__( 'Leave empty to keep it.', 'sysopenlang' ) . '</p><label><input type="checkbox" name="clear_api_key" value="1"> ' . esc_html__( 'Remove the saved key', 'sysopenlang' ) . '</label>'; }
		echo '</td></tr><tr><th><label for="openlingua-gemini-model">' . esc_html__( 'Model', 'sysopenlang' ) . '</label></th><td><select id="openlingua-gemini-model" name="model">'; foreach ( $models as $id => $label ) { echo '<option value="' . esc_attr( $id ) . '"' . selected( $s['model'], $id, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></td></tr><tr><th>' . esc_html__( 'Active provider', 'sysopenlang' ) . '</th><td><label><input type="radio" name="activate_provider" value="1"' . checked( Providers::active_id(), 'gemini', false ) . '> ' . esc_html__( 'Use Gemini for automatic translations', 'sysopenlang' ) . '</label></td></tr></table>'; submit_button( __( 'Save Gemini settings', 'sysopenlang' ) ); echo '</section></form>';
	}
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); } check_admin_referer( 'openlingua_save_gemini' ); $s = self::settings(); $encrypted = ! empty( $_POST['clear_api_key'] ) ? '' : $s['api_key']; $key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ); if ( '' !== $key ) { $encrypted = self::encrypt_secret( $key ); }
		update_option( self::OPTION, array( 'api_key' => $encrypted, 'model' => sanitize_text_field( wp_unslash( $_POST['model'] ?? 'gemini-3.5-flash-lite' ) ) ), false ); if ( $encrypted && ! empty( $_POST['activate_provider'] ) ) { Providers::activate( 'gemini' ); } delete_transient( 'openlingua_gemini_models' ); wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-fields', 'section' => 'gemini' ), admin_url( 'admin.php' ) ) ); exit;
	}
	private static function models() {
		$fallback = array( 'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite', 'gemini-3.6-flash' => 'Gemini 3.6 Flash' ); $current = self::settings()['model']; $cached = get_transient( 'openlingua_gemini_models' ); if ( is_array( $cached ) ) { return array( $current => $cached[ $current ] ?? $current ) + $cached; } if ( ! self::api_key() ) { return array( $current => $fallback[ $current ] ?? $current ) + $fallback; }
		$r = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=100', array( 'timeout' => 5, 'headers' => array( 'x-goog-api-key' => self::api_key() ) ) ); if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) { set_transient( 'openlingua_gemini_models', $fallback, HOUR_IN_SECONDS ); return $fallback; }
		$body = json_decode( wp_remote_retrieve_body( $r ), true ); $models = array(); foreach ( (array) ( $body['models'] ?? array() ) as $m ) { $methods = (array) ( $m['supportedGenerationMethods'] ?? array() ); if ( in_array( 'generateContent', $methods, true ) ) { $id = preg_replace( '#^models/#', '', $m['name'] ?? '' ); if ( $id && false === stripos( $id, 'image' ) ) { $models[ $id ] = $m['displayName'] ?? $id; } } } if ( ! $models ) { $models = $fallback; } set_transient( 'openlingua_gemini_models', $models, 12 * HOUR_IN_SECONDS ); return array( $current => $models[ $current ] ?? $current ) + $models;
	}
	public function translate( array $segments, $source_language, $target_language, array $context = array() ) {
		$key = self::api_key(); if ( ! $key ) { return new \WP_Error( 'openlingua_gemini_key', __( 'The Gemini API key is not configured.', 'sysopenlang' ) ); } $segments = array_filter( array_map( 'strval', $segments ), static function( $v ) { return '' !== trim( $v ); } ); $translated = array();
		$batch_size = max( 5, absint( Site_Settings::get()['batch_size'] ) );
		foreach ( array_chunk( $segments, $batch_size, true ) as $batch ) { $properties = array(); foreach ( $batch as $id => $value ) { $properties[ $id ] = array( 'type' => 'STRING' ); }
			$payload = array( 'contents' => array( array( 'parts' => array( array( 'text' => self::translation_prompt( $batch, $source_language, $target_language ) ) ) ) ), 'generationConfig' => array( 'responseMimeType' => 'application/json', 'responseSchema' => array( 'type' => 'OBJECT', 'properties' => $properties, 'required' => array_keys( $properties ) ) ) );
			$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( self::settings()['model'] ) . ':generateContent'; $r = wp_remote_post( $url, array( 'timeout' => 120, 'headers' => array( 'x-goog-api-key' => $key, 'content-type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) ); if ( is_wp_error( $r ) ) { return $r; } $body = json_decode( wp_remote_retrieve_body( $r ), true ); $status = wp_remote_retrieve_response_code( $r ); if ( $status < 200 || $status >= 300 ) { return new \WP_Error( 'openlingua_gemini_api', sanitize_text_field( $body['error']['message'] ?? 'Gemini API error ' . $status ) ); }
			$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? ''; $result = self::json_result( $text, $batch, 'gemini' ); if ( is_wp_error( $result ) ) { return $result; } $translated = array_merge( $translated, $result ); }
		return $translated;
	}
}
