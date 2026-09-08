<?php
namespace OpenLingua\Modules;

use OpenLingua\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/** Central user-facing behavior settings and first-run setup. */
final class Site_Settings implements Module {
	const OPTION = 'openlingua_site_settings';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 90 );
		add_action( 'admin_menu', array( __CLASS__, 'reorder_menu' ), 999 );
		add_action( 'admin_post_openlingua_save_site_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_openlingua_complete_setup', array( __CLASS__, 'complete_setup' ) );
		add_action( 'admin_post_openlingua_maintenance', array( __CLASS__, 'maintenance' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'load-toplevel_page_openlingua', array( __CLASS__, 'redirect_first_entry' ) );
	}

	public static function defaults() {
		return array(
			'missing_translation' => 'hide', 'new_translation_status' => 'draft', 'slug_mode' => 'translate',
			'copy_author' => true, 'copy_date' => false, 'copy_thumbnail' => true, 'copy_template' => true,
			'source_change' => 'mark', 'notify_source_changes' => true, 'automatic_on_create' => false,
			'automatic_result' => 'review', 'batch_size' => 40, 'max_attempts' => 3, 'monthly_job_limit' => 0,
			'translation_roles' => array( 'administrator', 'editor' ), 'notify_roles' => array( 'administrator' ), 'noindex_incomplete' => true, 'noindex_fallback' => false,
			'discovery_minutes' => 10, 'completed_job_retention' => 30, 'uninstall_mode' => 'preserve',
		);
	}

	public static function get() { return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() ); }

	public static function menu() {
		add_submenu_page( 'openlingua', __( 'OpenLingua setup', 'openlingua' ), __( 'Setup assistant', 'openlingua' ), 'manage_options', 'openlingua-setup', array( __CLASS__, 'setup_page' ) );
		add_submenu_page( 'openlingua', __( 'OpenLingua settings', 'openlingua' ), __( 'Settings', 'openlingua' ), 'manage_options', 'openlingua-settings', array( __CLASS__, 'page' ) );
	}

	public static function reorder_menu() {
		global $submenu;
		if ( empty( $submenu['openlingua'] ) ) { return; }
		$order = array( 'openlingua', 'openlingua-setup', 'openlingua-settings', 'openlingua-taxonomies', 'openlingua-strings', 'openlingua-shortcodes', 'openlingua-global-content', 'openlingua-divi-theme-builder', 'openlingua-menus', 'openlingua-fields', 'openlingua-jobs', 'openlingua-tools', 'openlingua-diagnostics' );
		usort( $submenu['openlingua'], static function ( $left, $right ) use ( $order ) {
			$left_order = array_search( $left[2], $order, true ); $right_order = array_search( $right[2], $order, true );
			return ( false === $left_order ? 999 : $left_order ) <=> ( false === $right_order ? 999 : $right_order );
		} );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'openlingua_page_openlingua-settings', 'openlingua_page_openlingua-setup' ), true ) ) { return; }
		wp_enqueue_style( 'openlingua-language-settings', plugins_url( 'assets/admin-language-settings.css', OPENLINGUA_FILE ), array(), OPENLINGUA_VERSION );
		if ( 'openlingua_page_openlingua-setup' === $hook ) {
			wp_enqueue_style( 'openlingua-setup', plugins_url( 'assets/admin-setup.css', OPENLINGUA_FILE ), array( 'openlingua-language-settings' ), OPENLINGUA_VERSION );
			wp_enqueue_style( 'openlingua-setup-interactions', plugins_url( 'assets/admin-setup-interactions.css', OPENLINGUA_FILE ), array( 'openlingua-setup' ), OPENLINGUA_VERSION );
			wp_enqueue_script( 'openlingua-setup', plugins_url( 'assets/admin-setup.js', OPENLINGUA_FILE ), array(), OPENLINGUA_VERSION, true );
			wp_localize_script( 'openlingua-setup', 'openlinguaSetupL10n', array( 'language' => __( 'language', 'openlingua' ), 'languages' => __( 'languages', 'openlingua' ), 'dropdown' => __( 'Dropdown', 'openlingua' ), 'inline' => __( 'Inline', 'openlingua' ), 'flags' => __( 'Flags', 'openlingua' ), 'noFlags' => __( 'No flags', 'openlingua' ), 'names' => __( 'Names', 'openlingua' ), 'empty' => '—' ) );
		}
	}

	public static function redirect_first_entry() {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( 'openlingua_setup_required' ) || get_option( 'openlingua_setup_complete' ) ) { return; }
		wp_safe_redirect( admin_url( 'admin.php?page=openlingua-setup' ) ); exit;
	}

	public static function setup_notice() {
		if ( ! get_option( 'openlingua_setup_required' ) || get_option( 'openlingua_setup_complete' ) || ! current_user_can( 'manage_options' ) ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = is_object( $screen ) ? (string) $screen->id : '';
		if ( '' === $screen_id || false === strpos( $screen_id, 'openlingua' ) || 'openlingua_page_openlingua-setup' === $screen_id ) { return; }
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Finish setting up OpenLingua.', 'openlingua' ) . '</strong> ' . esc_html__( 'Confirm the primary language, URL format, and language switcher before translating content.', 'openlingua' ) . ' <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=openlingua-setup' ) ) . '">' . esc_html__( 'Start setup', 'openlingua' ) . '</a></p></div>';
	}

	public static function setup_page() {
		$languages = Language_Catalog::merged(); $enabled = \OpenLingua\Languages::all(); $default = \OpenLingua\Languages::default_code(); $language_settings = Language_Settings::get(); $behavior = self::get();
		echo '<div class="wrap openlingua-settings openlingua-setup"><div class="openlingua-setup__shell"><aside class="openlingua-setup__sidebar"><div class="openlingua-setup__brand"><span class="dashicons dashicons-translation" aria-hidden="true"></span><strong>OpenLingua</strong></div><p>' . esc_html__( 'A multilingual website, configured around your workflow.', 'openlingua' ) . '</p><ol class="openlingua-setup__progress" aria-label="' . esc_attr__( 'Setup progress', 'openlingua' ) . '"><li class="is-active"><span>1</span><div><strong>' . esc_html__( 'Languages', 'openlingua' ) . '</strong><small>' . esc_html__( 'Choose your audience', 'openlingua' ) . '</small></div></li><li><span>2</span><div><strong>' . esc_html__( 'Web addresses', 'openlingua' ) . '</strong><small>' . esc_html__( 'Define the URL format', 'openlingua' ) . '</small></div></li><li><span>3</span><div><strong>' . esc_html__( 'Language selector', 'openlingua' ) . '</strong><small>' . esc_html__( 'Design and preview', 'openlingua' ) . '</small></div></li><li><span>4</span><div><strong>' . esc_html__( 'Translation workflow', 'openlingua' ) . '</strong><small>' . esc_html__( 'Choose safe defaults', 'openlingua' ) . '</small></div></li><li><span>5</span><div><strong>' . esc_html__( 'Review', 'openlingua' ) . '</strong><small>' . esc_html__( 'Confirm your choices', 'openlingua' ) . '</small></div></li></ol></aside><main class="openlingua-setup__main"><div class="openlingua-setup__hero"><p class="openlingua-setup__eyebrow">' . esc_html__( 'Initial setup', 'openlingua' ) . '</p><h1>' . esc_html__( 'Build your multilingual experience', 'openlingua' ) . '</h1><p>' . esc_html__( 'Five quick steps set the essentials. Nothing here locks you in.', 'openlingua' ) . '</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-openlingua-setup><input type="hidden" name="action" value="openlingua_complete_setup">';
		wp_nonce_field( 'openlingua_complete_setup' );
		echo '<section class="openlingua-setup__step is-active" data-step="1"><div class="openlingua-setup__heading"><div><span>01</span><h2>' . esc_html__( 'Who should your website speak to?', 'openlingua' ) . '</h2><p>' . esc_html__( 'Select every language you plan to publish. You can add more at any time.', 'openlingua' ) . '</p></div><strong data-language-count>0</strong></div><div class="openlingua-setup__toolbar"><label class="openlingua-setup__search"><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" data-language-search placeholder="' . esc_attr__( 'Search languages…', 'openlingua' ) . '"></label><label class="openlingua-select"><span>' . esc_html__( 'Original content language', 'openlingua' ) . '</span><select name="default_language" data-primary-language>';
		foreach ( $languages as $code => $language ) { echo '<option value="' . esc_attr( $code ) . '" ' . selected( $default, $code, false ) . '>' . esc_html( $language['name'] ) . '</option>'; }
		echo '</select></label></div><div class="openlingua-setup__languages">';
		foreach ( $languages as $code => $language ) {
			$search = strtolower( $code . ' ' . $language['name'] . ' ' . ( $language['native_name'] ?? '' ) );
			$flag_url = self::flag_asset_url( $code );
			$flag_markup = $flag_url ? '<img src="' . esc_url( $flag_url ) . '" alt="" loading="lazy" decoding="async">' : '<span>' . esc_html( $language['flag'] ?? '🌐' ) . '</span>';
			echo '<label data-language-option data-search="' . esc_attr( $search ) . '"><input type="checkbox" name="enabled_languages[]" value="' . esc_attr( $code ) . '" data-flag-url="' . esc_url( $flag_url ) . '" data-flag="' . esc_attr( $language['flag'] ?? '🌐' ) . '" data-native="' . esc_attr( $language['native_name'] ?? $language['name'] ) . '" data-english="' . esc_attr( $language['name'] ) . '" ' . checked( isset( $enabled[ $code ] ), true, false ) . '>' . wp_kses( $flag_markup, array( 'img' => array( 'src' => true, 'alt' => true, 'loading' => true, 'decoding' => true ), 'span' => array() ) ) . '<strong>' . esc_html( $language['native_name'] ?? $language['name'] ) . '</strong><small>' . esc_html( $language['name'] ) . '</small><i class="dashicons dashicons-yes-alt" aria-hidden="true"></i></label>';
		}
		echo '</div></section><section class="openlingua-setup__step" data-step="2" hidden><div class="openlingua-setup__heading"><div><span>02</span><h2>' . esc_html__( 'How should language URLs look?', 'openlingua' ) . '</h2><p>' . esc_html__( 'Clear, stable URLs help visitors and search engines understand each language.', 'openlingua' ) . '</p></div></div><div class="openlingua-setup__choices">';
		$url_choices = array( 'directory' => array( __( 'Language directories', 'openlingua' ), 'example.com/es/page/', __( 'Recommended', 'openlingua' ), 'admin-site-alt3' ), 'query' => array( __( 'Query parameter', 'openlingua' ), 'example.com/page/?lang=es', __( 'Easy compatibility', 'openlingua' ), 'admin-links' ), 'domain' => array( __( 'Separate domains', 'openlingua' ), 'es.example.com/page/', __( 'Advanced setup', 'openlingua' ), 'admin-multisite' ) );
		foreach ( $url_choices as $value => $choice ) { echo '<label class="openlingua-setup__choice"><input type="radio" name="url_mode" value="' . esc_attr( $value ) . '" ' . checked( $language_settings['url_mode'], $value, false ) . '><span class="dashicons dashicons-' . esc_attr( $choice[3] ) . '" aria-hidden="true"></span><strong>' . esc_html( $choice[0] ) . '</strong><code>' . esc_html( $choice[1] ) . '</code><small>' . esc_html( $choice[2] ) . '</small><i class="dashicons dashicons-yes-alt" aria-hidden="true"></i></label>'; }
		echo '</div></section><section class="openlingua-setup__step" data-step="3" hidden><div class="openlingua-setup__heading"><div><span>03</span><h2>' . esc_html__( 'Make the language selector yours', 'openlingua' ) . '</h2><p>' . esc_html__( 'Adjust the content and behavior while the preview updates instantly.', 'openlingua' ) . '</p></div></div><div class="openlingua-setup__designer"><div class="openlingua-setup__controls"><label><input type="checkbox" name="show_flag" value="1" data-preview-control checked> ' . esc_html__( 'Show flags or symbols', 'openlingua' ) . '</label><label><input type="checkbox" name="show_name" value="1" data-preview-control checked> ' . esc_html__( 'Show language names', 'openlingua' ) . '</label><label><input type="checkbox" name="show_native_name" value="1" data-preview-control ' . checked( ! empty( $language_settings['switcher']['show_native_name'] ), true, false ) . '> ' . esc_html__( 'Prefer native names', 'openlingua' ) . '</label><label><input type="checkbox" name="dropdown" value="1" data-preview-control ' . checked( ! empty( $language_settings['switcher']['dropdown'] ), true, false ) . '> ' . esc_html__( 'Display as a dropdown', 'openlingua' ) . '</label><label><input type="checkbox" name="footer" value="1" ' . checked( ! empty( $language_settings['switcher']['footer'] ), true, false ) . '> ' . esc_html__( 'Also place it in the footer', 'openlingua' ) . '</label></div><div class="openlingua-setup__preview"><span>' . esc_html__( 'Live preview', 'openlingua' ) . '</span><div class="openlingua-setup__mock-nav"><strong>' . esc_html__( 'Your website', 'openlingua' ) . '</strong><div data-switcher-preview></div></div></div></div></section>';
		echo '<section class="openlingua-setup__step" data-step="4" hidden><div class="openlingua-setup__heading"><div><span>04</span><h2>' . esc_html__( 'Set a comfortable editorial workflow', 'openlingua' ) . '</h2><p>' . esc_html__( 'These defaults protect unfinished translations and can be changed later.', 'openlingua' ) . '</p></div></div><div class="openlingua-setup__workflow"><fieldset><legend>' . esc_html__( 'New translations begin as', 'openlingua' ) . '</legend>' . wp_kses( self::wizard_radios( 'new_translation_status', $behavior['new_translation_status'], array( 'draft' => __( 'Draft — safest option', 'openlingua' ), 'pending' => __( 'Pending editorial review', 'openlingua' ), 'publish' => __( 'Published when permitted', 'openlingua' ) ) ), self::wizard_control_html() ) . '</fieldset><fieldset><legend>' . esc_html__( 'When a translation is missing', 'openlingua' ) . '</legend>' . wp_kses( self::wizard_radios( 'missing_translation', $behavior['missing_translation'], array( 'hide' => __( 'Hide that language', 'openlingua' ), 'home' => __( 'Send visitors to its homepage', 'openlingua' ), 'fallback' => __( 'Use the fallback language', 'openlingua' ) ) ), self::wizard_control_html() ) . '</fieldset><fieldset><legend>' . esc_html__( 'Media library', 'openlingua' ) . '</legend>' . wp_kses( self::wizard_radios( 'media_mode', $language_settings['media_mode'], array( 'unified' => __( 'Share media across languages', 'openlingua' ), 'separate' => __( 'Separate media by language', 'openlingua' ) ) ), self::wizard_control_html() ) . '</fieldset><fieldset><legend>' . esc_html__( 'When original content changes', 'openlingua' ) . '</legend>' . wp_kses( self::wizard_radios( 'source_change', $behavior['source_change'], array( 'mark' => __( 'Mark translations as outdated', 'openlingua' ), 'notify' => __( 'Mark outdated and notify', 'openlingua' ), 'automatic' => __( 'Queue automatic retranslation', 'openlingua' ) ) ), self::wizard_control_html() ) . '</fieldset></div></section>';
		echo '<section class="openlingua-setup__step" data-step="5" hidden><div class="openlingua-setup__complete"><div class="openlingua-setup__complete-icon"><span class="dashicons dashicons-yes" aria-hidden="true"></span></div><h2>' . esc_html__( 'Your foundation is ready', 'openlingua' ) . '</h2><p>' . esc_html__( 'Review the essentials below. Finishing will save them and open the complete settings area.', 'openlingua' ) . '</p><dl><div><dt>' . esc_html__( 'Languages', 'openlingua' ) . '</dt><dd data-summary-languages></dd></div><div><dt>' . esc_html__( 'URL format', 'openlingua' ) . '</dt><dd data-summary-url></dd></div><div><dt>' . esc_html__( 'Selector', 'openlingua' ) . '</dt><dd data-summary-switcher></dd></div><div><dt>' . esc_html__( 'Translation status', 'openlingua' ) . '</dt><dd data-summary-status></dd></div></dl></div></section><div class="openlingua-setup__actions"><button type="button" class="button button-large" data-setup-back hidden><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>' . esc_html__( 'Back', 'openlingua' ) . '</button><span data-step-label></span><button type="button" class="button button-primary button-large" data-setup-next>' . esc_html__( 'Continue', 'openlingua' ) . '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button><button type="submit" class="button button-primary button-large" data-setup-finish hidden>' . esc_html__( 'Save and start translating', 'openlingua' ) . '</button></div></form><p class="openlingua-setup__note">' . esc_html__( 'Every choice remains editable. OpenLingua never locks your content.', 'openlingua' ) . '</p></main></div></div>';
	}

	private static function wizard_radios( $name, $current, array $options ) {
		$html = '<div class="openlingua-setup__radio-grid">';
		foreach ( $options as $value => $label ) { $html .= '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . '><span>' . esc_html( $label ) . '</span><i class="dashicons dashicons-yes-alt" aria-hidden="true"></i></label>'; }
		return $html . '</div>';
	}
	private static function wizard_control_html() { return array( 'div' => array( 'class' => true ), 'label' => array(), 'input' => array( 'type' => true, 'name' => true, 'value' => true, 'checked' => true ), 'span' => array(), 'i' => array( 'class' => true, 'aria-hidden' => true ) ); }
	private static function flag_asset_url( $language_code ) {
		$map = array( 'en' => 'us', 'es' => 'es', 'de' => 'de', 'fr' => 'fr', 'it' => 'it', 'pt-br' => 'br', 'pt-pt' => 'pt', 'nl' => 'nl', 'pl' => 'pl', 'ru' => 'ru', 'uk' => 'ua', 'cs' => 'cz', 'sk' => 'sk', 'sl' => 'si', 'hr' => 'hr', 'sr' => 'rs', 'bs' => 'ba', 'bg' => 'bg', 'ro' => 'ro', 'hu' => 'hu', 'el' => 'gr', 'tr' => 'tr', 'sv' => 'se', 'da' => 'dk', 'no' => 'no', 'fi' => 'fi', 'is' => 'is', 'et' => 'ee', 'lv' => 'lv', 'lt' => 'lt', 'ga' => 'ie', 'cy' => 'gb', 'ca' => 'es', 'eu' => 'es', 'gl' => 'es', 'sq' => 'al', 'mk' => 'mk', 'hy' => 'am', 'ka' => 'ge', 'az' => 'az', 'kk' => 'kz', 'uz' => 'uz', 'ar' => 'sa', 'he' => 'il', 'fa' => 'ir', 'ur' => 'pk', 'ku' => 'iq', 'hi' => 'in', 'bn' => 'bd', 'pa' => 'in', 'ta' => 'in', 'ne' => 'np', 'th' => 'th', 'vi' => 'vn', 'id' => 'id', 'ms' => 'my', 'tl' => 'ph', 'zh-hans' => 'cn', 'zh-hant' => 'tw', 'ja' => 'jp', 'ko' => 'kr', 'eo' => 'un' );
		return isset( $map[ $language_code ] ) ? plugins_url( 'assets/flags/' . $map[ $language_code ] . '.svg', OPENLINGUA_FILE ) : '';
	}

	public static function page() {
		$s = self::get();
		echo '<div class="wrap openlingua-settings"><h1>' . esc_html__( 'OpenLingua settings', 'openlingua' ) . '</h1><p>' . esc_html__( 'Control how translations are created, reviewed, indexed, and maintained.', 'openlingua' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_save_site_settings">'; wp_nonce_field( 'openlingua_save_site_settings' );
		self::translation_section( $s ); self::automation_section( $s ); self::seo_section( $s ); self::maintenance_section( $s );
		submit_button( __( 'Save OpenLingua settings', 'openlingua' ), 'primary large' ); echo '</form>'; self::maintenance_actions( $s ); echo '</div>';
	}

	private static function translation_section( $s ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Translation behavior', 'openlingua' ) . '</h2><table class="form-table">';
		$rows = array(
			'missing_translation' => array( __( 'Missing translation', 'openlingua' ), array( 'hide' => __( 'Hide unavailable languages', 'openlingua' ), 'home' => __( 'Link to that language homepage', 'openlingua' ), 'fallback' => __( 'Use configured fallback language', 'openlingua' ) ) ),
			'new_translation_status' => array( __( 'New translation status', 'openlingua' ), array( 'draft' => __( 'Draft', 'openlingua' ), 'pending' => __( 'Pending review', 'openlingua' ), 'publish' => __( 'Publish when permitted', 'openlingua' ) ) ),
			'slug_mode' => array( __( 'Translated slug', 'openlingua' ), array( 'translate' => __( 'Create from translated title', 'openlingua' ), 'source' => __( 'Keep source slug', 'openlingua' ), 'manual' => __( 'Change only when edited manually', 'openlingua' ) ) ),
			'source_change' => array( __( 'When source changes', 'openlingua' ), array( 'mark' => __( 'Mark translation outdated', 'openlingua' ), 'notify' => __( 'Mark outdated and notify', 'openlingua' ), 'automatic' => __( 'Queue automatic retranslation', 'openlingua' ) ) ),
		);
		foreach ( $rows as $key => $row ) { echo '<tr><th>' . esc_html( $row[0] ) . '</th><td>' . wp_kses( self::select( $key, $s[ $key ], $row[1] ), self::control_html() ) . '</td></tr>'; }
		echo '</table>';
		foreach ( array( 'copy_author' => __( 'Copy author', 'openlingua' ), 'copy_date' => __( 'Copy publication date', 'openlingua' ), 'copy_thumbnail' => __( 'Copy featured image', 'openlingua' ), 'copy_template' => __( 'Copy page template', 'openlingua' ) ) as $key => $label ) { echo wp_kses( self::checkbox( $key, $s[ $key ], $label ), self::control_html() ); } echo '</section>';
	}

	private static function automation_section( $s ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Automatic translation workflow', 'openlingua' ) . '</h2>' . wp_kses( self::checkbox( 'automatic_on_create', $s['automatic_on_create'], __( 'Automatically queue translations when new source content is published', 'openlingua' ) ), self::control_html() ) . '<table class="form-table"><tr><th>' . esc_html__( 'Automatic result', 'openlingua' ) . '</th><td>' . wp_kses( self::select( 'automatic_result', $s['automatic_result'], array( 'review' => __( 'Ready for review', 'openlingua' ), 'draft' => __( 'Keep as draft', 'openlingua' ), 'publish' => __( 'Publish when permitted', 'openlingua' ) ) ), self::control_html() ) . '</td></tr><tr><th>' . esc_html__( 'Segments per request', 'openlingua' ) . '</th><td><input type="number" min="5" max="100" name="batch_size" value="' . absint( $s['batch_size'] ) . '"></td></tr><tr><th>' . esc_html__( 'Maximum attempts', 'openlingua' ) . '</th><td><input type="number" min="1" max="10" name="max_attempts" value="' . absint( $s['max_attempts'] ) . '"></td></tr><tr><th>' . esc_html__( 'Monthly job limit', 'openlingua' ) . '</th><td><input type="number" min="0" name="monthly_job_limit" value="' . absint( $s['monthly_job_limit'] ) . '"><p class="description">' . esc_html__( 'Use 0 for no plugin-side limit.', 'openlingua' ) . '</p></td></tr></table></section>';
	}

	private static function seo_section( $s ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'SEO, notifications, and permissions', 'openlingua' ) . '</h2>' . wp_kses( self::checkbox( 'noindex_incomplete', $s['noindex_incomplete'], __( 'Add noindex to incomplete translations', 'openlingua' ) ), self::control_html() ) . wp_kses( self::checkbox( 'noindex_fallback', $s['noindex_fallback'], __( 'Add noindex when fallback content is displayed', 'openlingua' ) ), self::control_html() ) . wp_kses( self::checkbox( 'notify_source_changes', $s['notify_source_changes'], __( 'Notify administrators when source content makes translations outdated', 'openlingua' ) ), self::control_html() ) . '<h3>' . esc_html__( 'Roles allowed to translate', 'openlingua' ) . '</h3>';
		foreach ( wp_roles()->roles as $role => $details ) { if ( 'administrator' === $role ) { continue; } echo '<label class="openlingua-check"><input type="checkbox" name="translation_roles[]" value="' . esc_attr( $role ) . '" ' . checked( in_array( $role, (array) $s['translation_roles'], true ), true, false ) . '> ' . esc_html( translate_user_role( $details['name'] ) ) . '</label>'; }
		echo '<p class="description">' . esc_html__( 'Administrators always retain access.', 'openlingua' ) . '</p><h3>' . esc_html__( 'Roles receiving source-change notices', 'openlingua' ) . '</h3>';
		foreach ( wp_roles()->roles as $role => $details ) { echo '<label class="openlingua-check"><input type="checkbox" name="notify_roles[]" value="' . esc_attr( $role ) . '" ' . checked( in_array( $role, (array) $s['notify_roles'], true ), true, false ) . '> ' . esc_html( translate_user_role( $details['name'] ) ) . '</label>'; }
		echo '</section>';
	}

	private static function maintenance_section( $s ) { echo '<section class="openlingua-card"><h2>' . esc_html__( 'Maintenance and privacy', 'openlingua' ) . '</h2><table class="form-table"><tr><th>' . esc_html__( 'String discovery duration', 'openlingua' ) . '</th><td><input type="number" min="1" max="1440" name="discovery_minutes" value="' . absint( $s['discovery_minutes'] ) . '"> ' . esc_html__( 'minutes', 'openlingua' ) . '</td></tr><tr><th>' . esc_html__( 'Completed job retention', 'openlingua' ) . '</th><td><input type="number" min="1" max="365" name="completed_job_retention" value="' . absint( $s['completed_job_retention'] ) . '"> ' . esc_html__( 'days', 'openlingua' ) . '</td></tr><tr><th>' . esc_html__( 'On uninstall', 'openlingua' ) . '</th><td>' . wp_kses( self::select( 'uninstall_mode', $s['uninstall_mode'], array( 'preserve' => __( 'Preserve all translation data', 'openlingua' ), 'remove' => __( 'Remove OpenLingua data', 'openlingua' ) ) ), self::control_html() ) . '<p class="description">' . esc_html__( 'Removal still requires confirmation from the Settings screen before uninstalling.', 'openlingua' ) . '</p></td></tr></table></section>'; }

	private static function maintenance_actions( $s ) {
		echo '<section class="openlingua-card"><h2>' . esc_html__( 'Maintenance actions', 'openlingua' ) . '</h2><p>' . esc_html__( 'These actions never delete translated posts, pages, terms, menus, or media files.', 'openlingua' ) . '</p>';
		$maintenance = isset( $_GET['maintenance'] ) ? sanitize_key( wp_unslash( $_GET['maintenance'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status set by the verified maintenance action.
		if ( 'term-sync' === $maintenance ) {
			$summary = get_transient( 'openlingua_term_sync_' . get_current_user_id() );
			if ( is_array( $summary ) ) {
				delete_transient( 'openlingua_term_sync_' . get_current_user_id() );
				/* translators: 1: number of synchronized translations, 2: number of translations that could not be synchronized. */
				echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Taxonomy repair completed: %1$d translations synchronized, %2$d could not be synchronized.', 'openlingua' ), absint( $summary['synced'] ?? 0 ), absint( $summary['failed'] ?? 0 ) ) ) . '</p></div>';
			}
		}
		foreach ( array( 'cache' => __( 'Clear OpenLingua caches', 'openlingua' ), 'jobs' => __( 'Delete expired completed jobs', 'openlingua' ), 'memory' => __( 'Clear translation memory', 'openlingua' ), 'term-sync' => __( 'Repair translated post taxonomies', 'openlingua' ) ) as $task => $label ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0"><input type="hidden" name="action" value="openlingua_maintenance"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">'; wp_nonce_field( 'openlingua_maintenance_' . $task ); if ( in_array( $task, array( 'memory', 'term-sync' ), true ) ) { echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Confirm', 'openlingua' ) . ' </label>'; } echo '<button class="button">' . esc_html( $label ) . '</button></form>'; }
		if ( 'remove' === $s['uninstall_mode'] ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="openlingua_maintenance"><input type="hidden" name="task" value="confirm-uninstall">'; wp_nonce_field( 'openlingua_maintenance_confirm-uninstall' ); echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I understand that uninstalling after this confirmation will remove OpenLingua data.', 'openlingua' ) . '</label> <button class="button">' . esc_html__( 'Authorize uninstall cleanup', 'openlingua' ) . '</button></form>'; }
		echo '</section>';
	}

	private static function select( $name, $current, array $options ) { $html = '<select name="' . esc_attr( $name ) . '">'; foreach ( $options as $value => $label ) { $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>'; } return $html . '</select>'; }
	private static function checkbox( $name, $checked, $label ) { return '<label class="openlingua-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $checked ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
	private static function control_html() { return array( 'label' => array( 'class' => true ), 'input' => array( 'type' => true, 'name' => true, 'value' => true, 'checked' => true ), 'select' => array( 'name' => true ), 'option' => array( 'value' => true, 'selected' => true ) ); }

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'openlingua' ) ); } check_admin_referer( 'openlingua_save_site_settings' );
		$d = self::defaults(); $clean = array();
		$allowed = array( 'missing_translation' => array( 'hide', 'home', 'fallback' ), 'new_translation_status' => array( 'draft', 'pending', 'publish' ), 'slug_mode' => array( 'translate', 'source', 'manual' ), 'source_change' => array( 'mark', 'notify', 'automatic' ), 'automatic_result' => array( 'review', 'draft', 'publish' ), 'uninstall_mode' => array( 'preserve', 'remove' ) );
		foreach ( $allowed as $key => $values ) { $value = sanitize_key( wp_unslash( $_POST[ $key ] ?? $d[ $key ] ) ); $clean[ $key ] = in_array( $value, $values, true ) ? $value : $d[ $key ]; }
		foreach ( array( 'copy_author', 'copy_date', 'copy_thumbnail', 'copy_template', 'automatic_on_create', 'noindex_incomplete', 'noindex_fallback', 'notify_source_changes' ) as $key ) { $clean[ $key ] = ! empty( $_POST[ $key ] ); }
		$clean['batch_size'] = min( 100, max( 5, absint( $_POST['batch_size'] ?? 40 ) ) ); $clean['max_attempts'] = min( 10, max( 1, absint( $_POST['max_attempts'] ?? 3 ) ) ); $clean['monthly_job_limit'] = absint( $_POST['monthly_job_limit'] ?? 0 ); $clean['discovery_minutes'] = min( 1440, max( 1, absint( $_POST['discovery_minutes'] ?? 10 ) ) ); $clean['completed_job_retention'] = min( 365, max( 1, absint( $_POST['completed_job_retention'] ?? 30 ) ) );
		$roles = array_keys( wp_roles()->roles );
		$clean['translation_roles'] = array_values( array_intersect( $roles, array_map( 'sanitize_key', (array) wp_unslash( $_POST['translation_roles'] ?? array() ) ) ) );
		if ( ! in_array( 'administrator', $clean['translation_roles'], true ) ) { $clean['translation_roles'][] = 'administrator'; }
		$clean['notify_roles'] = array_values( array_intersect( $roles, array_map( 'sanitize_key', (array) wp_unslash( $_POST['notify_roles'] ?? array() ) ) ) );
		foreach ( $roles as $role_name ) { $role = get_role( $role_name ); if ( ! $role ) { continue; } if ( in_array( $role_name, $clean['translation_roles'], true ) ) { $role->add_cap( 'openlingua_translate' ); } else { $role->remove_cap( 'openlingua_translate' ); } }
		$previous = self::get();
		if ( 'remove' !== $clean['uninstall_mode'] || $previous['uninstall_mode'] !== $clean['uninstall_mode'] ) { delete_option( 'openlingua_remove_data_confirmed' ); }
		update_option( self::OPTION, $clean, false ); wp_safe_redirect( admin_url( 'admin.php?page=openlingua-settings&updated=1' ) ); exit;
	}

	public static function maintenance() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'openlingua' ) ); }
		$task = sanitize_key( wp_unslash( $_POST['task'] ?? '' ) ); check_admin_referer( 'openlingua_maintenance_' . $task ); global $wpdb;
		if ( 'cache' === $task ) { foreach ( array( 'openlingua_rows', 'openlingua_groups', 'openlingua_routes', 'openlingua_strings', 'openlingua_memory' ) as $group ) { if ( function_exists( 'wp_cache_flush_group' ) ) { wp_cache_flush_group( $group ); } } }
		elseif ( 'jobs' === $task ) { $days = max( 1, absint( self::get()['completed_job_retention'] ) ); $cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status = 'complete' AND updated_at < %s", \OpenLingua\Database::table( 'jobs' ), $cutoff ) ); }
		elseif ( 'memory' === $task && ! empty( $_POST['confirm'] ) ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \OpenLingua\Database::table( 'memory' ) ) ); }
		elseif ( 'term-sync' === $task && ! empty( $_POST['confirm'] ) ) { $summary = \OpenLingua\Taxonomies::synchronize_existing_post_terms(); set_transient( 'openlingua_term_sync_' . get_current_user_id(), $summary, MINUTE_IN_SECONDS ); }
		elseif ( 'confirm-uninstall' === $task && ! empty( $_POST['confirm'] ) && 'remove' === self::get()['uninstall_mode'] ) { update_option( 'openlingua_remove_data_confirmed', 1, false ); }
		wp_safe_redirect( admin_url( 'admin.php?page=openlingua-settings&maintenance=' . $task ) ); exit;
	}

	public static function complete_setup() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'openlingua' ) ); } check_admin_referer( 'openlingua_complete_setup' );
		$catalog = Language_Catalog::merged();
		$default = sanitize_key( wp_unslash( $_POST['default_language'] ?? '' ) );
		$submitted = array_map( 'sanitize_key', (array) wp_unslash( $_POST['enabled_languages'] ?? array() ) );
		$enabled = array_intersect_key( $catalog, array_flip( array_unique( $submitted ) ) );
		if ( isset( $catalog[ $default ] ) ) { $enabled[ $default ] = $catalog[ $default ]; }
		if ( ! $enabled ) { $default = \OpenLingua\Languages::default_code(); $enabled[ $default ] = $catalog[ $default ] ?? \OpenLingua\Languages::all()[ $default ]; }
		update_option( 'openlingua_languages', $enabled, false ); update_option( 'openlingua_default_language', $default, false );
		$settings = Language_Settings::get(); $url_mode = sanitize_key( wp_unslash( $_POST['url_mode'] ?? 'directory' ) ); $settings['url_mode'] = in_array( $url_mode, array( 'directory', 'query', 'domain' ), true ) ? $url_mode : 'directory';
		$settings['media_mode'] = 'separate' === sanitize_key( wp_unslash( $_POST['media_mode'] ?? 'unified' ) ) ? 'separate' : 'unified';
		$settings['switcher']['show_flag'] = ! empty( $_POST['show_flag'] ); $settings['switcher']['show_name'] = ! empty( $_POST['show_name'] ); $settings['switcher']['show_native_name'] = ! empty( $_POST['show_native_name'] ); $settings['switcher']['show_current'] = true; $settings['switcher']['dropdown'] = ! empty( $_POST['dropdown'] ); $settings['switcher']['footer'] = ! empty( $_POST['footer'] );
		update_option( 'openlingua_language_settings', $settings, false );
		$behavior = self::get();
		$choices = array( 'new_translation_status' => array( 'draft', 'pending', 'publish' ), 'missing_translation' => array( 'hide', 'home', 'fallback' ), 'source_change' => array( 'mark', 'notify', 'automatic' ) );
		foreach ( $choices as $key => $allowed ) { $value = sanitize_key( wp_unslash( $_POST[ $key ] ?? $behavior[ $key ] ) ); if ( in_array( $value, $allowed, true ) ) { $behavior[ $key ] = $value; } }
		update_option( self::OPTION, $behavior, false ); update_option( 'openlingua_setup_complete', 1, false ); delete_option( 'openlingua_setup_required' ); update_option( 'openlingua_flush_rewrite_rules', 1, false );
		wp_safe_redirect( admin_url( 'admin.php?page=openlingua-settings&setup=complete' ) ); exit;
	}
}
