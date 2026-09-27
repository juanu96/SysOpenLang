<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Contracts\Translation_Provider;

defined( 'ABSPATH' ) || exit;

final class OpenAI_Provider implements Module, Translation_Provider {
	const OPTION = 'openlingua_openai_settings';
	const ID = 'openai';

	public static function hooks() {
		add_filter( 'openlingua_translation_providers', array( __CLASS__, 'register' ) );
		add_action( 'openlingua_advanced_settings_sections', array( __CLASS__, 'settings_section' ) );
		add_action( 'admin_post_openlingua_save_openai', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_openlingua_enqueue_openai_translation', array( __CLASS__, 'enqueue_translation' ) );
	}

	public static function register( $providers ) {
		$providers[] = new self();
		return $providers;
	}

	public function id() { return self::ID; }
	public function label() { return 'OpenAI'; }
	public function is_configured() { return '' !== self::api_key(); }

	public static function settings() {
		$stored = (array) get_option( self::OPTION, array() );
		return wp_parse_args( $stored, array( 'api_key' => '', 'model' => 'gpt-5.6-terra' ) );
	}

	public static function settings_url() {
		return add_query_arg( array( 'page' => 'openlingua-fields', 'section' => 'openai' ), admin_url( 'admin.php' ) );
	}

	public static function settings_section() {
		$settings = self::settings();
		$configured = '' !== self::decrypt( $settings['api_key'] );
		$models = self::available_models();
		echo '<form id="openlingua-openai" class="openlingua-provider-panel" data-openlingua-provider-panel="openai" role="tabpanel" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_openai">';
		wp_nonce_field( 'openlingua_save_openai' );
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Automatic translation with OpenAI', 'sysopenlang' ) . '</h2>';
		echo '<p>' . esc_html__( 'Connect your own OpenAI API account. SysOpenLang sends only the content selected for an automatic translation job.', 'sysopenlang' ) . '</p>';
		Providers::setup_guide( array(
			array( 'text' => __( 'Sign in to the OpenAI Platform or create an account.', 'sysopenlang' ), 'url' => 'https://platform.openai.com/', 'label' => __( 'OpenAI Platform', 'sysopenlang' ) ),
			array( 'text' => __( 'Open API keys and create a new secret key.', 'sysopenlang' ), 'url' => 'https://platform.openai.com/api-keys', 'label' => __( 'API keys', 'sysopenlang' ) ),
			array( 'text' => __( 'Add API billing or credits to the project if needed.', 'sysopenlang' ), 'url' => 'https://platform.openai.com/settings/organization/billing/overview', 'label' => __( 'API billing', 'sysopenlang' ) ),
			array( 'text' => __( 'Copy the key when it is shown and paste it below.', 'sysopenlang' ) ),
		), __( 'A ChatGPT subscription does not include OpenAI API usage.', 'sysopenlang' ) );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="openlingua-openai-key">' . esc_html__( 'OpenAI API key', 'sysopenlang' ) . '</label></th><td><input id="openlingua-openai-key" class="regular-text" type="password" name="api_key" value="" autocomplete="new-password" placeholder="sk-…">';
		if ( $configured ) { echo '<p class="description"><strong>' . esc_html__( 'A key is configured.', 'sysopenlang' ) . '</strong> ' . esc_html__( 'Leave this field empty to keep it.', 'sysopenlang' ) . '</p><label><input type="checkbox" name="clear_api_key" value="1"> ' . esc_html__( 'Remove the saved key', 'sysopenlang' ) . '</label>'; }
		echo '</td></tr><tr><th scope="row"><label for="openlingua-openai-model">' . esc_html__( 'Model', 'sysopenlang' ) . '</label></th><td><select id="openlingua-openai-model" name="model">';
		foreach ( $models as $model ) { echo '<option value="' . esc_attr( $model ) . '"' . selected( $settings['model'], $model, false ) . '>' . esc_html( self::model_label( $model ) ) . '</option>'; }
		echo '</select><p class="description">' . ( $configured ? esc_html__( 'Models available to the configured OpenAI project. The list is refreshed automatically.', 'sysopenlang' ) : esc_html__( 'Save an API key to load the models available to your OpenAI project.', 'sysopenlang' ) ) . '</p></td></tr><tr><th scope="row">' . esc_html__( 'Active provider', 'sysopenlang' ) . '</th><td><label><input type="radio" name="activate_provider" value="1"' . checked( Providers::active_id(), self::ID, false ) . '> ' . esc_html__( 'Use OpenAI for automatic translations', 'sysopenlang' ) . '</label></td></tr></table>';
		submit_button( __( 'Save OpenAI settings', 'sysopenlang' ) );
		echo '</section></form>';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		check_admin_referer( 'openlingua_save_openai' );
		$current = self::settings();
		$model = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
		if ( '' === $model ) { $model = 'gpt-5.6-terra'; }
		$encrypted = $current['api_key'];
		if ( ! empty( $_POST['clear_api_key'] ) ) { $encrypted = ''; }
		$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		if ( '' !== $key ) {
			if ( 0 !== strpos( $key, 'sk-' ) ) { self::redirect_settings( 'invalid-key' ); }
			$encrypted = self::encrypt( $key );
		}
		update_option( self::OPTION, array( 'api_key' => $encrypted, 'model' => $model ), false );
		if ( $encrypted && ! empty( $_POST['activate_provider'] ) ) { Providers::activate( self::ID ); }
		delete_transient( 'openlingua_openai_models' );
		self::redirect_settings( 'saved' );
	}

	private static function available_models() {
		$fallback = array( 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.6-sol' );
		$current = self::settings()['model'];
		$key = self::api_key();
		if ( '' === $key ) { return array_values( array_unique( array_merge( array( $current ), $fallback ) ) ); }
		$cached = get_transient( 'openlingua_openai_models' );
		if ( is_array( $cached ) && $cached ) { return array_values( array_unique( array_merge( array( $current ), $cached ) ) ); }
		$response = wp_remote_get( 'https://api.openai.com/v1/models', array( 'timeout' => 5, 'headers' => array( 'Authorization' => 'Bearer ' . $key ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( 'openlingua_openai_models', $fallback, HOUR_IN_SECONDS );
			return array_values( array_unique( array_merge( array( $current ), $fallback ) ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $item ) {
			$id = sanitize_text_field( $item['id'] ?? '' );
			if ( self::is_translation_model( $id ) ) { $models[] = $id; }
		}
		$models = array_values( array_unique( $models ) );
		natcasesort( $models );
		$models = array_values( $models );
		if ( ! $models ) { $models = $fallback; }
		set_transient( 'openlingua_openai_models', $models, 12 * HOUR_IN_SECONDS );
		return array_values( array_unique( array_merge( array( $current ), $models ) ) );
	}

	private static function is_translation_model( $id ) {
		if ( ! preg_match( '/^(gpt-5|gpt-4\.1|gpt-4o)/', $id ) ) { return false; }
		return ! preg_match( '/(audio|realtime|transcri|tts|image|search|codex|chat-latest)/i', $id );
	}

	private static function model_label( $id ) {
		$labels = array(
			'gpt-5.6-terra' => 'GPT-5.6 Terra — ' . __( 'Balanced', 'sysopenlang' ),
			'gpt-5.6-luna' => 'GPT-5.6 Luna — ' . __( 'Lower cost', 'sysopenlang' ),
			'gpt-5.6-sol' => 'GPT-5.6 Sol — ' . __( 'Highest quality', 'sysopenlang' ),
		);
		return $labels[ $id ] ?? $id;
	}

	private static function redirect_settings( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-fields', 'section' => 'openai', 'openai_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function enqueue_url( $source_id, $target_id, $return_to = '' ) {
		$url = add_query_arg( array( 'action' => 'openlingua_enqueue_openai_translation', 'source_id' => absint( $source_id ), 'target_id' => absint( $target_id ), 'return_to' => $return_to ), admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, 'openlingua_enqueue_openai_' . absint( $target_id ) );
	}

	public static function enqueue_translation() {
		$source_id = absint( $_GET['source_id'] ?? 0 );
		$target_id = absint( $_GET['target_id'] ?? 0 );
		check_admin_referer( 'openlingua_enqueue_openai_' . $target_id );
		if ( ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $target_id ) ) { wp_die( esc_html__( 'You cannot translate this content.', 'sysopenlang' ) ); }
		$target = \SysOpenLang\Translations::row( 'post', $target_id );
		$source = \SysOpenLang\Translations::row( 'post', $source_id );
		if ( ! $source || ! $target || $source->group_uuid !== $target->group_uuid ) { wp_die( esc_html__( 'These posts are not linked translations.', 'sysopenlang' ) ); }
		$return_to = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_to'] ?? '' ) ), '' );
		$editor_url = \SysOpenLang\Translation_Editor::url( $source_id, $target_id, $return_to );
		$job_id = Jobs::enqueue( $source_id, $target_id, $target->language, self::ID );
		if ( is_wp_error( $job_id ) ) { wp_safe_redirect( add_query_arg( 'automatic_translation', 'error', $editor_url ) ); exit; }
		wp_safe_redirect( add_query_arg( array( 'automatic_translation' => 'queued', 'job_id' => $job_id ), $editor_url ) );
		exit;
	}

	public function translate( array $segments, $source_language, $target_language, array $context = array() ) {
		$key = self::api_key();
		if ( '' === $key ) { return new \WP_Error( 'openlingua_openai_key', __( 'The OpenAI API key is not configured.', 'sysopenlang' ) ); }
		$segments = array_filter( array_map( 'strval', $segments ), static function( $value ) { return '' !== trim( $value ); } );
		if ( ! $segments ) { return array(); }
		$translated = array();
		$batch_size = max( 5, absint( Site_Settings::get()['batch_size'] ) );
		foreach ( array_chunk( $segments, $batch_size, true ) as $batch ) {
			$result = self::translate_batch( $batch, $source_language, $target_language, $key );
			if ( is_wp_error( $result ) ) { return $result; }
			$translated = array_merge( $translated, $result );
		}
		return $translated;
	}

	private static function translate_batch( array $segments, $source_language, $target_language, $key ) {
		$properties = array();
		foreach ( $segments as $id => $value ) { $properties[ (string) $id ] = array( 'type' => 'string' ); }
		$payload = array(
			'model' => self::settings()['model'],
			'instructions' => 'You are a professional website translator. Translate every JSON value from ' . $source_language . ' to ' . $target_language . '. Preserve HTML tags, shortcodes, placeholders, URLs, numbers, units, brand names, and JSON keys exactly. Translate only human-readable text. Do not add styles, tags, explanations, or content.',
			'input' => wp_json_encode( $segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'text' => array( 'format' => array( 'type' => 'json_schema', 'name' => 'openlingua_translation', 'strict' => true, 'schema' => array( 'type' => 'object', 'properties' => $properties, 'required' => array_keys( $properties ), 'additionalProperties' => false ) ) ),
			'store' => false,
		);
		$response = wp_remote_post( 'https://api.openai.com/v1/responses', array( 'timeout' => 120, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			/* translators: %d: HTTP response status code. */
			$message = $body['error']['message'] ?? sprintf( __( 'OpenAI returned HTTP status %d.', 'sysopenlang' ), $status );
			return new \WP_Error( 'openlingua_openai_api', sanitize_text_field( $message ) );
		}
		$text = self::response_text( $body );
		$result = json_decode( $text, true );
		if ( ! is_array( $result ) ) { return new \WP_Error( 'openlingua_openai_response', __( 'OpenAI returned a response that SysOpenLang could not read.', 'sysopenlang' ) ); }
		return array_intersect_key( $result, $segments );
	}

	private static function response_text( array $body ) {
		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) { return $body['output_text']; }
		foreach ( (array) ( $body['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if ( 'output_text' === ( $content['type'] ?? '' ) && isset( $content['text'] ) ) { return (string) $content['text']; }
			}
		}
		return '';
	}

	private static function api_key() { return self::decrypt( self::settings()['api_key'] ); }

	private static function encryption_key() { return hash( 'sha256', wp_salt( 'auth' ), true ); }

	private static function encrypt( $value ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return ''; }
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $value, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return $ciphertext ? 'v1:' . base64_encode( $iv . $tag . $ciphertext ) : '';
	}

	private static function decrypt( $value ) {
		if ( ! is_string( $value ) || 0 !== strpos( $value, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) { return ''; }
		$decoded = base64_decode( substr( $value, 3 ), true );
		if ( false === $decoded || strlen( $decoded ) < 29 ) { return ''; }
		$plain = openssl_decrypt( substr( $decoded, 28 ), 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, substr( $decoded, 0, 12 ), substr( $decoded, 12, 16 ) );
		return is_string( $plain ) ? $plain : '';
	}
}
