<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Database;

defined( 'ABSPATH' ) || exit;

final class Portability implements Module {
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_openlingua_export', array( __CLASS__, 'export_download' ) );
		add_action( 'admin_post_openlingua_import', array( __CLASS__, 'import_upload' ) );
		add_action( 'admin_post_openlingua_confirm_import', array( __CLASS__, 'confirm_import' ) );
		add_action( 'admin_post_openlingua_rollback_import', array( __CLASS__, 'rollback_import' ) );
	}

	public static function snapshot() {
		global $wpdb;
		return array(
			'format' => 'openlingua-portable', 'format_version' => 1, 'plugin_version' => SYSOPENLANG_VERSION,
			'generated_at' => gmdate( 'c' ), 'site_url' => home_url( '/' ),
			'settings' => array(
				'languages' => get_option( 'openlingua_languages', array() ),
				'default_language' => get_option( 'openlingua_default_language', '' ),
				'menu_map' => get_option( 'openlingua_menu_map', array() ),
				'meta_policies' => get_option( 'openlingua_meta_policies', array() ),
				'language_settings' => get_option( 'openlingua_language_settings', array() ),
				'custom_languages' => get_option( 'openlingua_custom_languages', array() ),
				'string_discovery' => (bool) get_option( 'openlingua_string_discovery', false ),
			),
			'translations' => $wpdb->get_results( $wpdb->prepare( 'SELECT group_uuid,element_type,element_id,language,source_language FROM %i', Database::table( 'translations' ) ), ARRAY_A ),
			'strings' => $wpdb->get_results( $wpdb->prepare( 'SELECT domain,string_key,source_language,source_text,translations,updated_at FROM %i', Database::table( 'strings' ) ), ARRAY_A ),
		);
	}

	public static function merge( array $data ) {
		if ( 'openlingua-portable' !== ( $data['format'] ?? '' ) || 1 !== absint( $data['format_version'] ?? 0 ) ) {
			return new \WP_Error( 'openlingua_import_format', __( 'Unsupported SysOpenLang import format.', 'sysopenlang' ) );
		}
		$settings = (array) ( $data['settings'] ?? array() );
		if ( ! empty( $settings['languages'] ) ) { update_option( 'openlingua_languages', \SysOpenLang\Admin::sanitize_languages( $settings['languages'] ) ); }
		if ( ! empty( $settings['default_language'] ) ) { update_option( 'openlingua_default_language', sanitize_key( $settings['default_language'] ) ); }
		update_option( 'openlingua_menu_map', (array) ( $settings['menu_map'] ?? array() ) );
		update_option( 'openlingua_meta_policies', (array) ( $settings['meta_policies'] ?? array() ) );
		if ( isset( $settings['language_settings'] ) ) { update_option( 'openlingua_language_settings', (array) $settings['language_settings'] ); }
		if ( isset( $settings['custom_languages'] ) ) { update_option( 'openlingua_custom_languages', (array) $settings['custom_languages'] ); }
		if ( isset( $settings['string_discovery'] ) ) { update_option( 'openlingua_string_discovery', (bool) $settings['string_discovery'] ); }
		foreach ( (array) ( $data['translations'] ?? array() ) as $row ) {
			$type = sanitize_key( $row['element_type'] ?? '' );
			$id   = absint( $row['element_id'] ?? 0 );
			$exists = 'post' === $type ? get_post( $id ) : ( 'term' === $type ? get_term( $id ) : false );
			if ( ! $id || ! $exists || is_wp_error( $exists ) ) { continue; }
			\SysOpenLang\Translations::assign( $type, $id, sanitize_key( $row['language'] ?? '' ), sanitize_text_field( $row['group_uuid'] ?? '' ), sanitize_key( $row['source_language'] ?? '' ) );
		}
		global $wpdb;
		$table = Database::table( 'strings' );
		foreach ( (array) ( $data['strings'] ?? array() ) as $row ) {
			if ( empty( $row['string_key'] ) ) { continue; }
			$wpdb->replace( $table, array(
				'domain' => sanitize_key( $row['domain'] ?? 'default' ), 'string_key' => sanitize_key( $row['string_key'] ?? '' ),
				'source_language' => sanitize_key( $row['source_language'] ?? '' ), 'source_text' => sanitize_textarea_field( $row['source_text'] ?? '' ),
				'translations' => wp_json_encode( json_decode( $row['translations'] ?? '{}', true ) ?: array() ), 'updated_at' => current_time( 'mysql' ),
			) );
		}
		return true;
	}

	public static function analyze( array $data ) {
		if ( 'openlingua-portable' !== ( $data['format'] ?? '' ) || 1 !== absint( $data['format_version'] ?? 0 ) ) {
			return new \WP_Error( 'openlingua_import_format', __( 'Unsupported SysOpenLang import format.', 'sysopenlang' ) );
		}
		$report = array( 'relationships' => 0, 'strings' => 0, 'missing_content' => 0, 'invalid_rows' => 0, 'conflicts' => 0 );
		foreach ( (array) ( $data['translations'] ?? array() ) as $row ) {
			$type = sanitize_key( $row['element_type'] ?? '' );
			$id = absint( $row['element_id'] ?? 0 );
			$language = sanitize_key( $row['language'] ?? '' );
			if ( ! in_array( $type, array( 'post', 'term' ), true ) || ! $id || ! preg_match( '/^[a-z][a-z0-9_-]{1,19}$/', $language ) ) { $report['invalid_rows']++; continue; }
			$exists = 'post' === $type ? get_post( $id ) : get_term( $id );
			if ( ! $exists || is_wp_error( $exists ) ) { $report['missing_content']++; continue; }
			$current = \SysOpenLang\Translations::row( $type, $id );
			if ( $current && ( $current->language !== $language || $current->group_uuid !== sanitize_text_field( $row['group_uuid'] ?? '' ) ) ) { $report['conflicts']++; }
			$report['relationships']++;
		}
		foreach ( (array) ( $data['strings'] ?? array() ) as $row ) {
			if ( empty( $row['string_key'] ) || ! is_array( json_decode( $row['translations'] ?? '{}', true ) ) ) { $report['invalid_rows']++; continue; }
			$report['strings']++;
		}
		return $report;
	}

	public static function admin_menu() {
		add_submenu_page( 'openlingua', __( 'Import and export', 'sysopenlang' ), __( 'Tools', 'sysopenlang' ), 'manage_options', 'openlingua-tools', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		$token = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview token.
		if ( $token ) { self::preview_page( $token ); return; }
		echo '<div class="wrap"><h1>' . esc_html__( 'Import and export', 'sysopenlang' ) . '</h1><h2>' . esc_html__( 'Export', 'sysopenlang' ) . '</h2><p>' . esc_html__( 'Download languages, relationships, strings, menus, and field policies as JSON.', 'sysopenlang' ) . '</p>';
		$url = wp_nonce_url( add_query_arg( 'action', 'openlingua_export', admin_url( 'admin-post.php' ) ), 'openlingua_export' );
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Download export', 'sysopenlang' ) . '</a></p><hr><h2>' . esc_html__( 'Merge import', 'sysopenlang' ) . '</h2><p>' . esc_html__( 'SysOpenLang validates the file and shows a preview before changing the site. A restorable backup is created when you confirm.', 'sysopenlang' ) . '</p><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_import">';
		wp_nonce_field( 'openlingua_import' );
		echo '<input type="file" name="openlingua_file" accept="application/json,.json" required>'; submit_button( __( 'Validate and preview', 'sysopenlang' ) );
		$backup = get_option( 'openlingua_last_import_backup', array() );
		if ( ! empty( $backup['snapshot'] ) ) {
			$rollback = wp_nonce_url( add_query_arg( 'action', 'openlingua_rollback_import', admin_url( 'admin-post.php' ) ), 'openlingua_rollback_import' );
			echo '<hr><h2>' . esc_html__( 'Last import backup', 'sysopenlang' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: backup date. */ __( 'A backup from %s is available.', 'sysopenlang' ), $backup['created_at'] ?? '' ) ) . '</p><p><a class="button" href="' . esc_url( $rollback ) . '">' . esc_html__( 'Restore pre-import state', 'sysopenlang' ) . '</a></p>';
		}
		echo '</form></div>';
	}

	private static function preview_page( $token ) {
		$key = self::preview_key( $token );
		$data = get_transient( $key );
		$report = is_array( $data ) ? self::analyze( $data ) : new \WP_Error( 'openlingua_preview_expired', __( 'The import preview expired. Upload the file again.', 'sysopenlang' ) );
		if ( is_wp_error( $report ) ) { wp_die( esc_html( $report->get_error_message() ) ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Import preview', 'sysopenlang' ) . '</h1><p>' . esc_html__( 'Review this summary before SysOpenLang changes the site.', 'sysopenlang' ) . '</p><table class="widefat striped"><tbody>';
		$labels = array( 'relationships' => __( 'Valid translation relationships', 'sysopenlang' ), 'strings' => __( 'Valid interface strings', 'sysopenlang' ), 'conflicts' => __( 'Existing relationships that will be updated', 'sysopenlang' ), 'missing_content' => __( 'Rows skipped because content is missing', 'sysopenlang' ), 'invalid_rows' => __( 'Invalid rows that will be skipped', 'sysopenlang' ) );
		foreach ( $labels as $key_name => $label ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . absint( $report[ $key_name ] ) . '</td></tr>'; }
		echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_confirm_import"><input type="hidden" name="preview" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'openlingua_confirm_import_' . $token );
		submit_button( __( 'Create backup and merge', 'sysopenlang' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=openlingua-tools' ) ) . '">' . esc_html__( 'Cancel', 'sysopenlang' ) . '</a></form></div>';
	}

	public static function export_download() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		check_admin_referer( 'openlingua_export' );
		nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=openlingua-export-' . gmdate( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( self::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); exit;
	}

	public static function import_upload() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		check_admin_referer( 'openlingua_import' );
		$file = null;
		if ( isset( $_FILES['openlingua_file'] ) && is_array( $_FILES['openlingua_file'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded-file fields are validated individually below.
			$uploaded = $_FILES['openlingua_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP upload paths must not be unslashed or text-sanitized.
			$file = array(
				'name'     => sanitize_file_name( $uploaded['name'] ?? '' ),
				'tmp_name' => isset( $uploaded['tmp_name'] ) ? (string) $uploaded['tmp_name'] : '',
				'error'    => absint( $uploaded['error'] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => absint( $uploaded['size'] ?? 0 ),
			);
		}
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || $file['size'] > 10 * MB_IN_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) { wp_die( esc_html__( 'Invalid or oversized import file.', 'sysopenlang' ) ); }
		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'json' => 'application/json' ) );
		if ( 'json' !== ( $filetype['ext'] ?? '' ) ) { wp_die( esc_html__( 'Only JSON import files are allowed.', 'sysopenlang' ) ); }
		$data = json_decode( file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$report = is_array( $data ) ? self::analyze( $data ) : new \WP_Error( 'openlingua_json', __( 'Invalid JSON document.', 'sysopenlang' ) );
		if ( is_wp_error( $report ) ) { wp_die( esc_html( $report->get_error_message() ) ); }
		$token = wp_generate_password( 20, false, false );
		set_transient( self::preview_key( $token ), $data, 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-tools', 'preview' => $token ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function confirm_import() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		$token = isset( $_POST['preview'] ) ? sanitize_key( wp_unslash( $_POST['preview'] ) ) : '';
		check_admin_referer( 'openlingua_confirm_import_' . $token );
		$data = get_transient( self::preview_key( $token ) );
		if ( ! is_array( $data ) ) { wp_die( esc_html__( 'The import preview expired. Upload the file again.', 'sysopenlang' ) ); }
		update_option( 'openlingua_last_import_backup', array( 'created_at' => current_time( 'mysql' ), 'snapshot' => self::snapshot() ), false );
		$result = self::merge( $data );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
		delete_transient( self::preview_key( $token ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-tools', 'imported' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function rollback_import() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		check_admin_referer( 'openlingua_rollback_import' );
		$backup = get_option( 'openlingua_last_import_backup', array() );
		if ( empty( $backup['snapshot'] ) || ! is_array( $backup['snapshot'] ) ) { wp_die( esc_html__( 'No import backup is available.', 'sysopenlang' ) ); }
		self::restore_snapshot( $backup['snapshot'] );
		delete_option( 'openlingua_last_import_backup' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'openlingua-tools', 'restored' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	private static function restore_snapshot( array $snapshot ) {
		$settings = (array) ( $snapshot['settings'] ?? array() );
		foreach ( array( 'languages', 'default_language', 'menu_map', 'meta_policies', 'language_settings', 'custom_languages', 'string_discovery' ) as $key ) {
			if ( array_key_exists( $key, $settings ) ) { update_option( 'openlingua_' . $key, $settings[ $key ] ); }
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'translations' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator-requested rollback of SysOpenLang custom-table data.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'strings' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator-requested rollback of SysOpenLang custom-table data.
		self::merge( $snapshot );
	}

	private static function preview_key( $token ) {
		return 'openlingua_import_' . get_current_user_id() . '_' . sanitize_key( $token );
	}
}
