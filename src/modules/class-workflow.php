<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Translations;

defined( 'ABSPATH' ) || exit;

final class Workflow implements Module {
	const STATUS_META = '_openlingua_translation_status';
	const HASH_META   = '_openlingua_source_hash';

	public static function hooks() {
		add_action( 'save_post', array( __CLASS__, 'track_changes' ), 30, 2 );
		add_filter( 'manage_posts_columns', array( __CLASS__, 'column' ) );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
	}

	public static function statuses() {
		return apply_filters( 'openlingua_workflow_statuses', array(
			'draft' => __( 'Draft', 'sysopenlang' ), 'in-progress' => __( 'In progress', 'sysopenlang' ),
			'complete' => __( 'Complete', 'sysopenlang' ), 'outdated' => __( 'Outdated', 'sysopenlang' ),
		) );
	}

	public static function content_hash( $post ) {
		return hash( 'sha256', $post->post_title . "\n" . $post->post_excerpt . "\n" . $post->post_content );
	}

	public static function track_changes( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) { return; }
		$row = Translations::row( 'post', $post_id );
		if ( ! $row ) { return; }
		if ( $row->source_language ) {
			$source_id = Translations::translated_id( 'post', $post_id, $row->source_language );
			$source = $source_id ? get_post( $source_id ) : null;
			if ( $source ) { update_post_meta( $post_id, self::HASH_META, self::content_hash( $source ) ); }
			return;
		}
		$hash = self::content_hash( $post );
		$behavior = Site_Settings::get();
		foreach ( Translations::group( 'post', $post_id ) as $translation_id ) {
			if ( absint( $translation_id ) === absint( $post_id ) ) { continue; }
			$translation_row = Translations::row( 'post', $translation_id );
			if ( ! $translation_row || $translation_row->source_language !== $row->language ) { continue; }
			$known_hash = get_post_meta( $translation_id, self::HASH_META, true );
			$current_status = get_post_meta( $translation_id, self::STATUS_META, true );
			if ( $known_hash && ! hash_equals( $known_hash, $hash ) && 'outdated' !== $current_status ) {
				update_post_meta( $translation_id, self::STATUS_META, 'outdated' );
				if ( 'automatic' === $behavior['source_change'] ) {
					$provider = Providers::active();
					if ( $provider && $provider->is_configured() ) { Jobs::enqueue( $post_id, $translation_id, $translation_row->language, $provider->id() ); }
				}
				if ( 'notify' === $behavior['source_change'] || ! empty( $behavior['notify_source_changes'] ) ) {
					/* translators: %s: source content title. */
					self::notify_source_change( $post );
				}
			}
		}
	}

	private static function notify_source_change( $post ) {
		$settings = Site_Settings::get();
		$emails = array( get_option( 'admin_email' ) );
		if ( function_exists( 'get_users' ) ) {
			$users = get_users( array( 'role__in' => (array) $settings['notify_roles'], 'fields' => array( 'user_email' ) ) );
			foreach ( $users as $user ) { $emails[] = $user->user_email; }
		}
		$emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ) ) ) );
		/* translators: %s: source content title. */
		if ( $emails ) { wp_mail( $emails, sprintf( __( 'Translation update needed: %s', 'sysopenlang' ), $post->post_title ), __( 'Source content changed and one or more translations are now marked as outdated.', 'sysopenlang' ) ); }
	}

	public static function mark_created( $translation_id, $source_id ) {
		$source = get_post( $source_id );
		if ( $source ) { update_post_meta( $translation_id, self::HASH_META, self::content_hash( $source ) ); }
		update_post_meta( $translation_id, self::STATUS_META, 'draft' );
	}

	public static function column( $columns ) {
		$columns['openlingua_status'] = __( 'Translation', 'sysopenlang' );
		return $columns;
	}

	public static function column_value( $column, $post_id ) {
		if ( 'openlingua_status' !== $column ) { return; }
		$status = get_post_meta( $post_id, self::STATUS_META, true ) ?: 'complete';
		$labels = self::statuses();
		echo esc_html( $labels[ $status ] ?? $status );
	}
}
