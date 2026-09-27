<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Database;
use SysOpenLang\Module_Registry;

defined( 'ABSPATH' ) || exit;

final class Diagnostics implements Module {
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'debug_information', array( __CLASS__, 'site_health' ) );
	}

	public static function report() {
		global $wpdb;
		$tables = array();
		foreach ( array( 'translations', 'strings', 'jobs' ) as $name ) {
			$table = Database::table( $name );
			$tables[ $name ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}
		$providers = array();
		foreach ( Providers::all() as $id => $provider ) { $providers[ $id ] = array( 'name' => $provider->label(), 'configured' => $provider->is_configured() ); }
		return array(
			'version' => SYSOPENLANG_VERSION, 'database_version' => get_option( 'openlingua_db_version' ),
			'languages' => count( \SysOpenLang\Languages::all() ), 'modules' => Module_Registry::all(),
			'url_mode' => \SysOpenLang\Modules\Language_Settings::get()['url_mode'],
			'tables' => $tables, 'providers' => array_keys( Providers::all() ), 'provider_status' => $providers, 'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	public static function admin_menu() {
		add_submenu_page( 'openlingua', __( 'Diagnostics', 'sysopenlang' ), __( 'Diagnostics', 'sysopenlang' ), 'manage_options', 'openlingua-diagnostics', array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'openlingua_page_openlingua-diagnostics', 'sysopenlang_page_openlingua-diagnostics' ), true ) ) { return; }
		wp_enqueue_style( 'openlingua-diagnostics', plugins_url( 'assets/admin-diagnostics.css', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION );
	}

	public static function page() {
		$report = self::report();
		$tables_ok = ! in_array( false, $report['tables'], true );
		$configured = array_filter( $report['provider_status'], static function( $provider ) { return ! empty( $provider['configured'] ); } );
		/* translators: %d: number of enabled languages. */
		$enabled_languages_message = sprintf( _n( '%d language is enabled.', '%d languages are enabled.', $report['languages'], 'sysopenlang' ), $report['languages'] );
		/* translators: %d: number of configured translation services. */
		$configured_services_message = sprintf( _n( '%d translation service is configured.', '%d translation services are configured.', count( $configured ), 'sysopenlang' ), count( $configured ) );
		$checks = array(
			array( 'status' => $tables_ok ? 'good' : 'critical', 'title' => __( 'Translation database', 'sysopenlang' ), 'message' => $tables_ok ? __( 'The storage needed for translations is working correctly.', 'sysopenlang' ) : __( 'One or more translation tables are missing. SysOpenLang cannot reliably save all translation data.', 'sysopenlang' ), 'action' => $tables_ok ? '' : admin_url( 'plugins.php' ), 'action_label' => __( 'Review installed plugins', 'sysopenlang' ) ),
			array( 'status' => $report['languages'] > 1 ? 'good' : 'warning', 'title' => __( 'Site languages', 'sysopenlang' ), 'message' => $report['languages'] > 1 ? $enabled_languages_message : __( 'Only one language is enabled. Add another language before creating translations.', 'sysopenlang' ), 'action' => admin_url( 'admin.php?page=openlingua' ), 'action_label' => __( 'Manage languages', 'sysopenlang' ) ),
			array( 'status' => $configured ? 'good' : 'info', 'title' => __( 'Automatic translation', 'sysopenlang' ), 'message' => $configured ? $configured_services_message : __( 'No automatic translation service is configured. Manual translation will continue to work normally.', 'sysopenlang' ), 'action' => admin_url( 'admin.php?page=openlingua-fields' ), 'action_label' => __( 'Configure services', 'sysopenlang' ) ),
			array( 'status' => $report['cron_disabled'] ? 'warning' : 'good', 'title' => __( 'Background translations', 'sysopenlang' ), 'message' => $report['cron_disabled'] ? __( 'WordPress automatic scheduling is disabled. Translation jobs require a server cron task to run on time.', 'sysopenlang' ) : __( 'WordPress can start queued translation jobs automatically.', 'sysopenlang' ), 'action' => admin_url( 'admin.php?page=openlingua-jobs' ), 'action_label' => __( 'View translation jobs', 'sysopenlang' ) ),
		);
		$attention = count( array_filter( $checks, static function( $check ) { return in_array( $check['status'], array( 'warning', 'critical' ), true ); } ) );
		/* translators: %d: number of diagnostic items requiring attention. */
		$attention_message = sprintf( _n( '%d item below may require action.', '%d items below may require action.', $attention, 'sysopenlang' ), $attention );
		echo '<div class="wrap openlingua-diagnostics"><h1>' . esc_html__( 'SysOpenLang site health', 'sysopenlang' ) . '</h1><p class="openlingua-diagnostics__intro">' . esc_html__( 'A simple overview of the features SysOpenLang needs to translate your website.', 'sysopenlang' ) . '</p>';
		echo '<div class="openlingua-health-summary openlingua-health-summary--' . ( $attention ? 'attention' : 'good' ) . '"><span class="dashicons ' . ( $attention ? 'dashicons-warning' : 'dashicons-yes-alt' ) . '" aria-hidden="true"></span><div><h2>' . esc_html( $attention ? __( 'Some items need your attention', 'sysopenlang' ) : __( 'SysOpenLang is ready', 'sysopenlang' ) ) . '</h2><p>' . esc_html( $attention ? $attention_message : __( 'The main translation components are working correctly.', 'sysopenlang' ) ) . '</p></div></div>';
		echo '<div class="openlingua-health-grid">';
		foreach ( $checks as $check ) {
			$labels = array( 'good' => __( 'Working', 'sysopenlang' ), 'warning' => __( 'Check this', 'sysopenlang' ), 'critical' => __( 'Action required', 'sysopenlang' ), 'info' => __( 'Optional', 'sysopenlang' ) );
			echo '<section class="openlingua-health-card openlingua-health-card--' . esc_attr( $check['status'] ) . '"><div class="openlingua-health-card__top"><h2>' . esc_html( $check['title'] ) . '</h2><span class="openlingua-health-badge">' . esc_html( $labels[ $check['status'] ] ) . '</span></div><p>' . esc_html( $check['message'] ) . '</p>';
			if ( $check['action'] ) { echo '<a class="button" href="' . esc_url( $check['action'] ) . '">' . esc_html( $check['action_label'] ) . '</a>'; }
			echo '</section>';
		}
		echo '</div><section class="openlingua-diagnostics__details"><h2>' . esc_html__( 'Installation details', 'sysopenlang' ) . '</h2><dl><div><dt>' . esc_html__( 'SysOpenLang version', 'sysopenlang' ) . '</dt><dd>' . esc_html( $report['version'] ) . '</dd></div><div><dt>' . esc_html__( 'Language URL format', 'sysopenlang' ) . '</dt><dd>' . esc_html( self::url_mode_label( $report['url_mode'] ) ) . '</dd></div><div><dt>' . esc_html__( 'Available translation services', 'sysopenlang' ) . '</dt><dd>' . esc_html( implode( ', ', array_map( static function( $provider ) { return $provider['name']; }, $report['provider_status'] ) ) ) . '</dd></div></dl>';
		echo '<details><summary>' . esc_html__( 'Technical information for support', 'sysopenlang' ) . '</summary><p>' . esc_html__( 'A developer or support technician may ask you to copy this information. It does not contain your API keys.', 'sysopenlang' ) . '</p><textarea class="large-text code" rows="16" readonly>' . esc_textarea( wp_json_encode( $report, JSON_PRETTY_PRINT ) ) . '</textarea></details></section>';
		self::builder_inspector();
		echo '</div>';
	}

	private static function builder_inspector() {
		$post_id = isset( $_GET['inspect_post'] ) ? absint( $_GET['inspect_post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only diagnostic filter.
		echo '<section class="openlingua-diagnostics__details"><h2>' . esc_html__( 'Visual builder field inspector', 'sysopenlang' ) . '</h2><p>' . esc_html__( 'Check which custom fields SysOpenLang can safely recognize as structured visual-builder content. This tool does not change the page.', 'sysopenlang' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="openlingua-diagnostics"><label for="openlingua-inspect-post"><strong>' . esc_html__( 'Page or post ID', 'sysopenlang' ) . '</strong></label> <input id="openlingua-inspect-post" name="inspect_post" type="number" min="1" value="' . esc_attr( $post_id ) . '"> <button class="button button-secondary">' . esc_html__( 'Inspect fields', 'sysopenlang' ) . '</button></form>';
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) { echo '<p class="notice notice-error inline"><span>' . esc_html__( 'That content item could not be found.', 'sysopenlang' ) . '</span></p>'; }
			else {
				$labels = array(
					'translatable-document'  => __( 'Recognized builder content', 'sysopenlang' ),
					'acf-field'               => __( 'Managed separately by ACF support', 'sysopenlang' ),
					'excluded-key'            => __( 'Technical field excluded for safety', 'sysopenlang' ),
					'opaque-object'           => __( 'Object data cannot be edited safely', 'sysopenlang' ),
					'not-a-builder-document'  => __( 'Not recognized as builder content', 'sysopenlang' ),
				);
				$title = get_the_title( $post );
				if ( ! $title ) {
					/* translators: %d: WordPress content ID. */
					$title = sprintf( __( 'Content #%d', 'sysopenlang' ), $post_id );
				}
				echo '<h3>' . esc_html( $title ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Custom field', 'sysopenlang' ) . '</th><th>' . esc_html__( 'Result', 'sysopenlang' ) . '</th></tr></thead><tbody>';
				foreach ( \SysOpenLang\Structured_Meta_Content::inspect( $post ) as $key => $reason ) { echo '<tr><td><code>' . esc_html( $key ) . '</code></td><td>' . esc_html( $labels[ $reason ] ?? $reason ) . '</td></tr>'; }
				echo '</tbody></table>';
			}
		}
		echo '</section>';
	}

	private static function url_mode_label( $mode ) {
		$labels = array( 'directory' => __( 'Language directories, such as /es/', 'sysopenlang' ), 'query' => __( 'Language parameter, such as ?lang=es', 'sysopenlang' ), 'domain' => __( 'A separate domain for each language', 'sysopenlang' ) );
		return $labels[ $mode ] ?? $mode;
	}

	public static function site_health( $info ) {
		$report = self::report();
		$fields = array();
		foreach ( array( 'version', 'database_version', 'languages', 'cron_disabled' ) as $key ) { $fields[ $key ] = array( 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'value' => is_bool( $report[ $key ] ) ? ( $report[ $key ] ? 'true' : 'false' ) : (string) $report[ $key ] ); }
		$info['openlingua'] = array( 'label' => 'SysOpenLang', 'description' => __( 'Multilingual plugin health information.', 'sysopenlang' ), 'fields' => $fields );
		return $info;
	}
}
