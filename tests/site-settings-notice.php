<?php
define( 'ABSPATH', __DIR__ . '/' );

$site_settings_options = array(
	'openlingua_setup_required' => 1,
	'openlingua_setup_complete' => 0,
);
$site_settings_screen_id = 'dashboard';

function add_action() {}
function get_option( $key, $default = false ) { global $site_settings_options; return $site_settings_options[ $key ] ?? $default; }
function current_user_can() { return true; }
function get_current_screen() { global $site_settings_screen_id; return (object) array( 'id' => $site_settings_screen_id ); }
function esc_html__( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }

require dirname( __DIR__ ) . '/src/contracts/interface-module.php';
require dirname( __DIR__ ) . '/src/modules/class-site-settings.php';

function site_settings_notice_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	echo "PASS: {$message}\n";
}

ob_start();
\OpenLingua\Modules\Site_Settings::setup_notice();
$dashboard_notice = ob_get_clean();
site_settings_notice_assert( '' === $dashboard_notice, 'does not show setup notices on unrelated administration screens' );

$site_settings_screen_id = 'openlingua_page_openlingua-settings';
ob_start();
\OpenLingua\Modules\Site_Settings::setup_notice();
$openlingua_notice = ob_get_clean();
site_settings_notice_assert( false !== strpos( $openlingua_notice, 'Finish setting up OpenLingua.' ), 'shows the setup notice on OpenLingua screens' );

$site_settings_screen_id = 'openlingua_page_openlingua-setup';
ob_start();
\OpenLingua\Modules\Site_Settings::setup_notice();
$setup_notice = ob_get_clean();
site_settings_notice_assert( '' === $setup_notice, 'does not duplicate the setup notice on its own setup screen' );
