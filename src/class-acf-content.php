<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

/** Discovers and updates independently translatable ACF values. */
final class ACF_Content {
	const SOURCE_SNAPSHOT_META = '_openlingua_acf_source_snapshot';
	private static $text_types = array( 'text', 'textarea', 'wysiwyg' );

	public static function available() {
		return function_exists( 'get_field_objects' ) && function_exists( 'update_field' );
	}

	public static function extract( $post_id ) {
		if ( ! self::available() ) { return array(); }
		$fields = get_field_objects( $post_id, false );
		if ( ! is_array( $fields ) ) { return array(); }
		$segments = array();
		$post_type = get_post_type( $post_id );
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) || empty( $field['name'] ) ) { continue; }
			self::walk( $field, $field['value'] ?? null, array(), array(), $field['key'], $post_type, $segments );
		}
		return $segments;
	}

	public static function values( $post_id ) {
		$values = array();
		foreach ( self::extract( $post_id ) as $segment ) { $values[ $segment['id'] ] = $segment['value']; }
		return $values;
	}

	public static function source_snapshot( $post_id ) {
		$segments = array();
		foreach ( self::extract( $post_id ) as $segment ) {
			$segments[ $segment['id'] ] = array(
				'compatibility' => self::compatibility_key( $segment ),
				'value' => self::normalized_value( $segment['value'], $segment['format'] ),
			);
		}
		return array( 'version' => 1, 'segments' => $segments );
	}

	public static function aligned_values( $source_id, $target_id, array $snapshot = array() ) {
		$target_values = self::values( $target_id );
		$previous = 1 === (int) ( $snapshot['version'] ?? 0 ) && is_array( $snapshot['segments'] ?? null ) ? $snapshot['segments'] : array();
		if ( ! $previous ) { return $target_values; }
		$pools = array();
		foreach ( $previous as $old_id => $descriptor ) {
			if ( isset( $descriptor['compatibility'], $descriptor['value'] ) ) { $pools[ $descriptor['compatibility'] . ':' . $descriptor['value'] ][] = (string) $old_id; }
		}
		$aligned = array();
		$used = array();
		foreach ( self::extract( $source_id ) as $segment ) {
			$key = self::compatibility_key( $segment ) . ':' . self::normalized_value( $segment['value'], $segment['format'] );
			foreach ( $pools[ $key ] ?? array() as $old_id ) {
				if ( isset( $used[ $old_id ] ) || ! array_key_exists( $old_id, $target_values ) ) { continue; }
				$aligned[ $segment['id'] ] = $target_values[ $old_id ];
				$used[ $old_id ] = true;
				continue 2;
			}
			$aligned[ $segment['id'] ] = '';
		}
		return $aligned;
	}

	public static function save( $source_id, $target_id, array $submitted, $allow_html = false ) {
		if ( ! self::available() ) { return; }
		$source_segments = self::extract( $source_id );
		$source_fields = get_field_objects( $source_id, false );
		$source_by_key = array();
		foreach ( (array) $source_fields as $field ) { if ( ! empty( $field['key'] ) ) { $source_by_key[ $field['key'] ] = $field; } }
		$target_fields = get_field_objects( $target_id, false );
		$target_by_key = array();
		foreach ( (array) $target_fields as $field ) { if ( ! empty( $field['key'] ) ) { $target_by_key[ $field['key'] ] = $field; } }
		$updates = array();
		$snapshot = function_exists( 'get_post_meta' ) ? get_post_meta( $target_id, self::SOURCE_SNAPSHOT_META, true ) : array();
		$existing_values = self::aligned_values( $source_id, $target_id, is_array( $snapshot ) ? $snapshot : array() );
		foreach ( $source_segments as $segment ) {
			$root_key = $segment['root_key'];
			if ( ! array_key_exists( $root_key, $updates ) ) { $updates[ $root_key ] = isset( $source_by_key[ $root_key ] ) ? $source_by_key[ $root_key ]['value'] : null; }
			if ( array_key_exists( $segment['id'], $existing_values ) && '' !== (string) $existing_values[ $segment['id'] ] ) {
				$updates[ $root_key ] = self::set_path( $updates[ $root_key ], $segment['path'], $existing_values[ $segment['id'] ] );
			}
		}

		foreach ( $source_segments as $segment ) {
			if ( ! array_key_exists( $segment['id'], $submitted ) ) { continue; }
			$root_key = $segment['root_key'];
			$value = (string) $submitted[ $segment['id'] ];
			$value = 'html' === $segment['format'] ? ( $allow_html ? $value : wp_kses_post( $value ) ) : sanitize_textarea_field( $value );
			$updates[ $root_key ] = self::set_path( $updates[ $root_key ], $segment['path'], $value );
		}

		foreach ( $updates as $field_key => $value ) { update_field( $field_key, $value, $target_id ); }
	}

	private static function walk( array $field, $value, array $path, array $parents, $root_key, $post_type, array &$segments ) {
		$name = $field['name'] ?? '';
		$type = $field['type'] ?? '';
		$policy = \SysOpenLang\Modules\Metadata::policy( $name, $post_type );
		if ( in_array( $policy, array( 'copy', 'ignore' ), true ) ) { return; }
		$label = $field['label'] ?? $name;
		$labels = array_merge( $parents, array( $label ) );

		if ( in_array( $type, self::$text_types, true ) ) {
			if ( ! is_scalar( $value ) || '' === trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) ) ) { return; }
			self::add_segment( $segments, $root_key, $path, $labels, (string) $value, 'wysiwyg' === $type ? 'html' : 'plain' );
			return;
		}

		if ( 'link' === $type && is_array( $value ) && ! empty( $value['title'] ) ) {
			self::add_segment( $segments, $root_key, array_merge( $path, array( 'title' ) ), array_merge( $labels, array( __( 'Link text', 'sysopenlang' ) ) ), (string) $value['title'], 'plain' );
			return;
		}

		if ( in_array( $type, array( 'group', 'clone' ), true ) ) {
			foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub_field ) {
				$sub_name = $sub_field['name'] ?? '';
				$sub_key = $sub_field['key'] ?? '';
				if ( ! $sub_name || ! $sub_key ) { continue; }
				$sub_value = is_array( $value ) ? ( $value[ $sub_name ] ?? ( $value[ $sub_field['key'] ] ?? null ) ) : null;
				self::walk( $sub_field, $sub_value, array_merge( $path, array( $sub_key ) ), $labels, $root_key, $post_type, $segments );
			}
			return;
		}

		if ( 'repeater' === $type && is_array( $value ) ) {
			foreach ( $value as $row_index => $row ) {
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub_field ) {
					$sub_name = $sub_field['name'] ?? '';
					$sub_key = $sub_field['key'] ?? '';
					if ( ! $sub_name || ! $sub_key ) { continue; }
					$sub_value = is_array( $row ) ? ( $row[ $sub_name ] ?? ( $row[ $sub_field['key'] ] ?? null ) ) : null;
					/* translators: %d: repeater row number. */
					self::walk( $sub_field, $sub_value, array_merge( $path, array( $row_index, $sub_key ) ), array_merge( $labels, array( sprintf( __( 'Row %d', 'sysopenlang' ), $row_index + 1 ) ) ), $root_key, $post_type, $segments );
				}
			}
			return;
		}

		if ( 'flexible_content' === $type && is_array( $value ) ) {
			foreach ( $value as $row_index => $row ) {
				$layout_name = is_array( $row ) ? ( $row['acf_fc_layout'] ?? '' ) : '';
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					if ( ( $layout['name'] ?? '' ) !== $layout_name ) { continue; }
					$layout_label = $layout['label'] ?? $layout_name;
					foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub_field ) {
						$sub_name = $sub_field['name'] ?? '';
						$sub_key = $sub_field['key'] ?? '';
						if ( ! $sub_name || ! $sub_key ) { continue; }
						$sub_value = $row[ $sub_name ] ?? ( $row[ $sub_field['key'] ] ?? null );
						self::walk( $sub_field, $sub_value, array_merge( $path, array( $row_index, $sub_key ) ), array_merge( $labels, array( $layout_label . ' ' . ( $row_index + 1 ) ) ), $root_key, $post_type, $segments );
					}
				}
			}
		}
	}

	private static function add_segment( array &$segments, $root_key, array $path, array $labels, $value, $format ) {
		$id_path = $path ? implode( '_', array_map( 'strval', $path ) ) : 'value';
		$segments[] = array( 'id' => sanitize_key( 'acf_' . $root_key . '_' . $id_path ), 'label' => implode( ' — ', $labels ), 'value' => $value, 'format' => $format, 'root_key' => $root_key, 'path' => $path );
	}

	private static function compatibility_key( array $segment ) {
		$path = array_values( array_filter( (array) ( $segment['path'] ?? array() ), static function ( $part ) { return ! is_int( $part ) && ! ctype_digit( (string) $part ); } ) );
		return (string) ( $segment['root_key'] ?? '' ) . ':' . implode( ':', array_map( 'sanitize_key', $path ) ) . ':' . (string) ( $segment['format'] ?? 'plain' );
	}

	private static function normalized_value( $value, $format ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $value );
		return 'html' === $format ? trim( preg_replace( '/>\s+</u', '><', $value ) ) : trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	private static function set_path( $root, array $path, $value ) {
		if ( ! $path ) { return $value; }
		if ( ! is_array( $root ) ) { $root = array(); }
		$cursor =& $root;
		foreach ( $path as $index => $part ) {
			if ( $index === count( $path ) - 1 ) { $cursor[ $part ] = $value; break; }
			if ( ! isset( $cursor[ $part ] ) || ! is_array( $cursor[ $part ] ) ) { $cursor[ $part ] = array(); }
			$cursor =& $cursor[ $part ];
		}
		return $root;
	}
}
