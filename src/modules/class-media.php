<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Admin;
use SysOpenLang\Contracts\Module;
use SysOpenLang\Database;
use SysOpenLang\Languages;
use SysOpenLang\Translations;

defined( 'ABSPATH' ) || exit;

/** Controls whether WordPress media is shared or filtered by content language. */
final class Media implements Module {
	const MODE_UNIFIED = 'unified';
	const MODE_SEPARATE = 'separate';
	const TEXT_META = '_openlingua_media_texts';
	const SHARED_META = '_openlingua_media_shared';

	public static function hooks() {
		add_action( 'add_attachment', array( __CLASS__, 'assign_uploaded_attachment' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'prepare_admin_query' ), 4 );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'prepare_modal_query' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'filter_clauses' ), 8, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 20, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_attachment_fields' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), 20, 2 );
		add_filter( 'wp_get_attachment_caption', array( __CLASS__, 'caption' ), 20, 2 );
	}

	public static function mode() {
		$mode = sanitize_key( Language_Settings::get()['media_mode'] ?? self::MODE_UNIFIED );
		return self::MODE_SEPARATE === $mode ? self::MODE_SEPARATE : self::MODE_UNIFIED;
	}

	public static function current_admin_language() {
		$language = Admin::content_language();
		return 'all' === $language || Languages::is_valid( $language ) ? $language : Languages::default_code();
	}

	public static function assign_uploaded_attachment( $attachment_id ) {
		if ( self::MODE_SEPARATE !== self::mode() || ! $attachment_id ) { return; }
		$language = self::current_admin_language();
		Translations::assign( 'post', absint( $attachment_id ), 'all' === $language ? Languages::default_code() : $language );
	}

	public static function attachment_fields( $fields, $attachment ) {
		$language = self::current_admin_language();
		if ( 'all' === $language ) { $language = Languages::default_code(); }
		$language_name = Languages::all()[ $language ]['name'] ?? strtoupper( $language );
		$defaults = array(
			'alt' => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'title' => (string) $attachment->post_title,
			'caption' => (string) $attachment->post_excerpt,
			'description' => (string) $attachment->post_content,
		);
		foreach ( $defaults as $key => $fallback ) {
			$fields[ 'openlingua_' . $key ] = array(
				/* translators: 1: media field name, 2: language name. */
				'label' => sprintf( __( '%1$s (%2$s)', 'sysopenlang' ), ucfirst( $key ), $language_name ),
				'input' => in_array( $key, array( 'caption', 'description' ), true ) ? 'textarea' : 'text',
				'value' => self::translated_text( $attachment->ID, $key, $language, $fallback ),
				'helps' => __( 'Stored for this language without duplicating the media file.', 'sysopenlang' ),
			);
		}
		if ( self::MODE_SEPARATE === self::mode() ) {
			$fields['openlingua_shared'] = array( 'label' => __( 'Language availability', 'sysopenlang' ), 'input' => 'html', 'html' => '<label><input type="checkbox" name="attachments[' . absint( $attachment->ID ) . '][openlingua_shared]" value="1" ' . checked( (bool) get_post_meta( $attachment->ID, self::SHARED_META, true ), true, false ) . '> ' . esc_html__( 'Available in every language', 'sysopenlang' ) . '</label>', 'helps' => __( 'Keep this file visible in all language-specific media libraries.', 'sysopenlang' ) );
		}
		return $fields;
	}

	public static function save_attachment_fields( $post, $attachment ) {
		$attachment_id = absint( $post['ID'] ?? 0 );
		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) { return $post; }
		$language = self::current_admin_language();
		if ( 'all' === $language ) { $language = Languages::default_code(); }
		$texts = (array) get_post_meta( $attachment_id, self::TEXT_META, true );
		foreach ( array( 'alt', 'title', 'caption', 'description' ) as $key ) {
			$field = 'openlingua_' . $key;
			if ( ! array_key_exists( $field, $attachment ) ) { continue; }
			$texts[ $language ][ $key ] = 'description' === $key ? wp_kses_post( $attachment[ $field ] ) : sanitize_textarea_field( $attachment[ $field ] );
		}
		update_post_meta( $attachment_id, self::TEXT_META, $texts );
		if ( ! empty( $attachment['openlingua_shared'] ) ) { update_post_meta( $attachment_id, self::SHARED_META, 1 ); }
		else { delete_post_meta( $attachment_id, self::SHARED_META ); }
		return $post;
	}

	public static function translated_text( $attachment_id, $field, $language = '', $fallback = '' ) {
		$texts = (array) get_post_meta( absint( $attachment_id ), self::TEXT_META, true );
		foreach ( Languages::fallback_chain( $language ?: Languages::current() ) as $candidate ) {
			if ( isset( $texts[ $candidate ][ $field ] ) && '' !== trim( (string) $texts[ $candidate ][ $field ] ) ) { return (string) $texts[ $candidate ][ $field ]; }
		}
		return (string) $fallback;
	}

	public static function image_attributes( $attributes, $attachment ) {
		if ( ! $attachment ) { return $attributes; }
		$attributes['alt'] = self::translated_text( $attachment->ID, 'alt', '', $attributes['alt'] ?? '' );
		$title = self::translated_text( $attachment->ID, 'title' );
		if ( '' !== $title ) { $attributes['title'] = $title; }
		return $attributes;
	}

	public static function caption( $caption, $attachment_id ) {
		return self::translated_text( $attachment_id, 'caption', '', $caption );
	}

	public static function prepare_admin_query( $query ) {
		if ( ! is_admin() || ! $query || 'attachment' !== $query->get( 'post_type' ) ) { return; }
		$query->set( 'openlingua_media_library', self::mode() );
		if ( self::MODE_SEPARATE === self::mode() ) { $query->set( 'openlingua_media_language', self::current_admin_language() ); }
	}

	public static function prepare_modal_query( $query ) {
		$query['openlingua_media_library'] = self::mode();
		if ( self::MODE_SEPARATE === self::mode() ) { $query['openlingua_media_language'] = self::current_admin_language(); }
		return $query;
	}

	public static function filter_clauses( $clauses, $query ) {
		$mode = sanitize_key( $query->get( 'openlingua_media_library' ) );
		if ( ! in_array( $mode, array( self::MODE_UNIFIED, self::MODE_SEPARATE ), true ) ) { return $clauses; }
		global $wpdb;
		$table = Database::table( 'translations' );
		if ( self::MODE_UNIFIED === $mode ) {
			$clauses['where'] .= $wpdb->prepare(
				" AND (NOT EXISTS (SELECT 1 FROM %i ol_media_any WHERE ol_media_any.element_type = 'post' AND ol_media_any.element_id = %i.ID) OR EXISTS (SELECT 1 FROM %i ol_media_source WHERE ol_media_source.element_type = 'post' AND ol_media_source.element_id = %i.ID AND ol_media_source.source_language = %s))",
				$table,
				$wpdb->posts,
				$table,
				$wpdb->posts,
				''
			);
			return $clauses;
		}

		$language = sanitize_key( $query->get( 'openlingua_media_language' ) );
		if ( 'all' === $language ) { return $clauses; }
		if ( ! Languages::is_valid( $language ) ) { $language = Languages::default_code(); }
		$matching = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM %i ol_media_lang WHERE ol_media_lang.element_type = 'post' AND ol_media_lang.element_id = %i.ID AND ol_media_lang.language = %s)",
			$table,
			$wpdb->posts,
			$language
		);
		$postmeta_table = isset( $wpdb->postmeta ) ? $wpdb->postmeta : preg_replace( '/posts$/', 'postmeta', $wpdb->posts );
		$shared = $wpdb->prepare( "EXISTS (SELECT 1 FROM %i ol_media_meta WHERE ol_media_meta.post_id = %i.ID AND ol_media_meta.meta_key = %s AND ol_media_meta.meta_value = '1')", $postmeta_table, $wpdb->posts, self::SHARED_META );
		if ( Languages::default_code() === $language ) {
			$unassigned = $wpdb->prepare(
				"NOT EXISTS (SELECT 1 FROM %i ol_media_unassigned WHERE ol_media_unassigned.element_type = 'post' AND ol_media_unassigned.element_id = %i.ID)",
				$table,
				$wpdb->posts
			);
			$clauses['where'] .= ' AND (' . $shared . ' OR ' . $unassigned . ' OR ' . $matching . ')';
		} else {
			$clauses['where'] .= ' AND (' . $shared . ' OR ' . $matching . ')';
		}
		return $clauses;
	}
}
