<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

final class Database {
	const SCHEMA_VERSION = '3';

	public static function maybe_upgrade() {
		if ( self::SCHEMA_VERSION !== (string) get_option( 'openlingua_db_version', '' ) ) {
			self::activate();
			update_option( 'openlingua_flush_rewrite_rules', 1 );
		}
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'openlingua_' . $name;
	}

	public static function install_new_site( $site ) {
		if ( ! is_multisite() || ! $site || empty( $site->blog_id ) ) { return; }
		switch_to_blog( $site->blog_id );
		self::install();
		restore_current_blog();
	}

	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	private static function install() {
		global $wpdb;
		$previous_schema = (string) get_option( 'openlingua_db_version', '' );
		if ( '' === $previous_schema && false === get_option( 'openlingua_setup_complete', false ) ) {
			add_option( 'openlingua_setup_required', 1, '', 'no' );
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = self::table( 'translations' );
		$strings = self::table( 'strings' );
		$jobs    = self::table( 'jobs' );
		$memory  = self::table( 'memory' );

		dbDelta( "CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_uuid varchar(36) NOT NULL,
			element_type varchar(32) NOT NULL,
			element_id bigint(20) unsigned NOT NULL,
			language varchar(20) NOT NULL,
			source_language varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY element (element_type,element_id),
			UNIQUE KEY translation (group_uuid,element_type,language),
			KEY language (language)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$strings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			domain varchar(100) NOT NULL,
			string_key varchar(191) NOT NULL,
			source_language varchar(20) NOT NULL,
			source_text longtext NOT NULL,
			translations longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY string_identity (domain,string_key)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			target_id bigint(20) unsigned NOT NULL,
			target_language varchar(20) NOT NULL,
			provider varchar(100) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
			available_at datetime NULL,
			started_at datetime NULL,
			payload longtext NULL,
			error longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY runnable (status,available_at),
			KEY target (target_id,target_language)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$memory} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_language varchar(20) NOT NULL,
			target_language varchar(20) NOT NULL,
			source_hash char(64) NOT NULL,
			source_text longtext NOT NULL,
			translation longtext NOT NULL,
			format varchar(10) NOT NULL DEFAULT 'text',
			context varchar(191) NOT NULL DEFAULT '',
			origin varchar(20) NOT NULL DEFAULT 'manual',
			approved tinyint(1) unsigned NOT NULL DEFAULT 1,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY memory_identity (source_language,target_language,source_hash,format),
			KEY language_pair (source_language,target_language)
		) {$charset};" );

		if ( ! get_option( 'openlingua_languages' ) ) {
			add_option( 'openlingua_languages', array(
				'en' => array( 'name' => 'English', 'native_name' => 'English', 'locale' => 'en_US', 'flag' => '🇺🇸', 'direction' => 'ltr' ),
				'es' => array( 'name' => 'Spanish', 'native_name' => 'Español', 'locale' => 'es_ES', 'flag' => '🇪🇸', 'direction' => 'ltr' ),
			) );
			add_option( 'openlingua_default_language', 'en' );
		}
		if ( ! get_option( 'openlingua_language_settings' ) ) {
			add_option( 'openlingua_language_settings', array( 'url_mode' => 'directory', 'domains' => array(), 'admin_language' => 'site-default', 'hidden_languages' => array(), 'browser_redirect' => 'off', 'media_mode' => 'unified', 'switcher' => array( 'show_flag' => true, 'show_name' => true, 'show_native_name' => false, 'show_current' => true, 'dropdown' => false, 'missing' => 'home', 'footer' => false, 'menu_locations' => array(), 'menu_position' => 'last' ) ) );
		}
		self::run_migrations( $previous_schema );
		update_option( 'openlingua_db_version', self::SCHEMA_VERSION, false );
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) { $role->add_cap( 'openlingua_translate' ); }
		}
	}

	/** Runs data migrations after dbDelta has made the current schema available. */
	private static function run_migrations( $previous_schema ) {
		$completed = (array) get_option( 'openlingua_completed_migrations', array() );
		$migrations = array(
			'2-normalize-schema-version' => static function ( $from ) { unset( $from ); },
			'3-contextual-translation-memory' => static function ( $from ) { unset( $from ); },
		);
		foreach ( $migrations as $migration => $callback ) {
			if ( in_array( $migration, $completed, true ) ) { continue; }
			$callback( $previous_schema );
			$completed[] = $migration;
			update_option( 'openlingua_completed_migrations', array_values( array_unique( $completed ) ), false );
			do_action( 'openlingua_database_migrated', $migration, $previous_schema, self::SCHEMA_VERSION );
		}
	}
}
