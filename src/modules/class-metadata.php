<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;

defined( 'ABSPATH' ) || exit;

final class Metadata implements Module {
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_openlingua_save_meta_policies', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function policies() {
		return array( 'translate', 'copy', 'copy-once', 'ignore' );
	}

	public static function policy( $key, $post_type = '' ) {
		$configured = get_option( 'openlingua_meta_policies', array() );
		$policy = $configured[ $post_type ][ $key ] ?? $configured['*'][ $key ] ?? 'copy-once';
		if ( 0 === strpos( $key, '_openlingua_' ) || in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) { $policy = 'ignore'; }
		if ( 0 === strpos( $key, '_' ) && isset( $configured[ $post_type ][ ltrim( $key, '_' ) ] ) ) { $policy = 'copy'; }
		$policy = apply_filters( 'openlingua_meta_policy', $policy, $key, $post_type );
		return in_array( $policy, self::policies(), true ) ? $policy : 'copy-once';
	}

	public static function copy( $source_id, $target_id, $post_type ) {
		foreach ( get_post_meta( $source_id ) as $key => $values ) {
			if ( 'ignore' === self::policy( $key, $post_type ) ) { continue; }
			foreach ( $values as $value ) { add_post_meta( $target_id, $key, maybe_unserialize( $value ) ); }
		}
	}

	public static function synchronize( $source_id, $target_id, $post_type ) {
		foreach ( get_post_meta( $source_id ) as $key => $values ) {
			if ( 'copy' !== self::policy( $key, $post_type ) ) { continue; }
			delete_post_meta( $target_id, $key );
			foreach ( $values as $value ) { add_post_meta( $target_id, $key, maybe_unserialize( $value ) ); }
		}
	}

	public static function admin_menu() {
		add_submenu_page( 'openlingua', __( 'SysOpenLang advanced settings', 'sysopenlang' ), __( 'Advanced settings', 'sysopenlang' ), 'manage_options', 'openlingua-fields', array( __CLASS__, 'page' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'openlingua_page_openlingua-fields', 'sysopenlang_page_openlingua-fields' ), true ) ) { return; }
		wp_enqueue_style( 'openlingua-language-settings', plugins_url( 'assets/admin-language-settings.css', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION );
		wp_enqueue_script( 'openlingua-provider-tabs', plugins_url( 'assets/admin-provider-tabs.js', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION, true );
	}

	public static function page() {
		$rows = array();
		foreach ( (array) get_option( 'openlingua_meta_policies', array() ) as $post_type => $keys ) {
			foreach ( (array) $keys as $key => $policy ) { $rows[] = $post_type . '|' . $key . '|' . $policy; }
		}
		echo '<div class="wrap openlingua-settings"><h1>' . esc_html__( 'SysOpenLang advanced settings', 'sysopenlang' ) . '</h1>';
		echo '<p>' . esc_html__( 'Technical controls for exceptional multilingual content behavior. The automatic defaults are suitable for most websites.', 'sysopenlang' ) . '</p>';
		$provider_notice = isset( $_GET['openai_notice'] ) ? sanitize_key( wp_unslash( $_GET['openai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice.
		if ( 'saved' === $provider_notice ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'OpenAI settings saved.', 'sysopenlang' ) . '</p></div>'; }
		if ( 'invalid-key' === $provider_notice ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The API key must begin with sk-. The previous key was kept.', 'sysopenlang' ) . '</p></div>'; }
		echo '<div class="openlingua-provider-settings" data-openlingua-provider-tabs><div class="nav-tab-wrapper" role="tablist" aria-label="' . esc_attr__( 'Automatic translation provider', 'sysopenlang' ) . '">';
		$active_provider = Providers::active_id();
		echo '<button type="button" class="nav-tab" role="tab" data-openlingua-provider-tab="openai">OpenAI' . ( 'openai' === $active_provider ? ' ✓' : '' ) . '</button>';
		echo '<button type="button" class="nav-tab" role="tab" data-openlingua-provider-tab="anthropic">Claude' . ( 'anthropic' === $active_provider ? ' ✓' : '' ) . '</button>';
		echo '<button type="button" class="nav-tab" role="tab" data-openlingua-provider-tab="gemini">Gemini' . ( 'gemini' === $active_provider ? ' ✓' : '' ) . '</button>';
		echo '<button type="button" class="nav-tab" role="tab" data-openlingua-provider-tab="google-translate">Google Translate' . ( 'google-translate' === $active_provider ? ' ✓' : '' ) . '</button></div>';
		do_action( 'openlingua_advanced_settings_sections' );
		echo '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_meta_policies"><section class="openlingua-card"><h2>' . esc_html__( 'Custom field policies', 'sysopenlang' ) . '</h2>';
		echo '<p>' . esc_html__( 'Override how specific WordPress or ACF fields behave when a translation is created.', 'sysopenlang' ) . '</p>';
		echo '<details><summary>' . esc_html__( 'Edit technical rules', 'sysopenlang' ) . '</summary><p>' . esc_html__( 'One rule per line: post-type|meta-key|policy. Use * for every post type. Policies: translate, copy, copy-once, ignore.', 'sysopenlang' ) . '</p>';
		wp_nonce_field( 'openlingua_save_meta_policies' );
		echo '<textarea class="large-text code" rows="12" name="rules">' . esc_textarea( implode( "\n", $rows ) ) . '</textarea></details></section>'; submit_button(); echo '</form></div>';
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		check_admin_referer( 'openlingua_save_meta_policies' );
		$rules = array();
		foreach ( preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( wp_unslash( $_POST['rules'] ?? '' ) ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( 3 !== count( $parts ) || ! in_array( $parts[2], self::policies(), true ) ) { continue; }
			$post_type = '*' === $parts[0] ? '*' : sanitize_key( $parts[0] );
			$key = sanitize_text_field( $parts[1] );
			if ( $post_type && $key ) { $rules[ $post_type ][ $key ] = $parts[2]; }
		}
		update_option( 'openlingua_meta_policies', $rules );
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-fields', 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}
}
