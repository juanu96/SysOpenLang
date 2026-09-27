<?php
namespace SysOpenLang;

use SysOpenLang\Contracts\Content_Extractor;

defined( 'ABSPATH' ) || exit;

/** Extracts user-facing text from Elementor's JSON document. */
final class Elementor_Content implements Content_Extractor {
	const DATA_META = '_elementor_data';

	public function id() { return 'elementor'; }
	public function label() { return __( 'Elementor content', 'sysopenlang' ); }

	public function supports( $post ) {
		return '' !== trim( (string) get_post_meta( $post->ID, self::DATA_META, true ) );
	}

	public function extract( $post ) {
		$document = self::document( $post );
		$segments = array();
		self::walk_elements( $document, $segments );
		return array_values( $segments );
	}

	public function values( $post ) {
		$values = array();
		foreach ( $this->extract( $post ) as $segment ) { $values[ $segment['id'] ] = $segment['value']; }
		return $values;
	}

	public function apply( $source, $target, array $translations, $target_language = '' ) {
		unset( $target_language );
		$document = self::document( $source );
		self::walk_apply( $document, $translations );
		update_post_meta( $target->ID, self::DATA_META, wp_slash( wp_json_encode( $document ) ) );
		return $document;
	}

	private static function document( $post ) {
		$post = get_post( $post );
		$data = $post ? get_post_meta( $post->ID, self::DATA_META, true ) : '';
		if ( is_array( $data ) ) { return $data; }
		$decoded = json_decode( wp_unslash( (string) $data ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function walk_elements( array $elements, array &$segments ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$element_id = sanitize_key( (string) ( $element['id'] ?? 'element-' . $index ) );
			$widget = sanitize_key( (string) ( $element['widgetType'] ?? $element['elType'] ?? 'element' ) );
			self::walk_settings( (array) ( $element['settings'] ?? array() ), $element_id, $widget, array(), $segments );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) { self::walk_elements( $element['elements'], $segments ); }
		}
	}

	private static function walk_settings( array $settings, $element_id, $widget, array $path, array &$segments ) {
		foreach ( $settings as $key => $value ) {
			$current = array_merge( $path, array( (string) $key ) );
			if ( is_array( $value ) ) {
				foreach ( $value as $row => $nested ) {
					if ( ! is_array( $nested ) ) { continue; }
					$row_id = sanitize_key( (string) ( $nested['_id'] ?? $row ) );
					self::walk_settings( $nested, $element_id, $widget, array_merge( $current, array( $row_id ) ), $segments );
				}
				continue;
			}
			if ( ! self::is_translatable( $key, $value ) ) { continue; }
			$id = self::segment_id( $element_id, $current );
			$segments[ $id ] = array(
				'id' => $id,
				'label' => ucwords( str_replace( array( '-', '_' ), ' ', $widget . ' — ' . $key ) ),
				'value' => (string) $value,
				'format' => self::is_html( $value ) ? 'html' : 'text',
				'element_id' => $element_id,
				'path' => $current,
			);
		}
	}

	private static function walk_apply( array &$elements, array $translations ) {
		foreach ( $elements as $index => &$element ) {
			if ( ! is_array( $element ) ) { continue; }
			$element_id = sanitize_key( (string) ( $element['id'] ?? 'element-' . $index ) );
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) { self::apply_settings( $element['settings'], $element_id, array(), $translations ); }
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) { self::walk_apply( $element['elements'], $translations ); }
		}
		unset( $element );
	}

	private static function apply_settings( array &$settings, $element_id, array $path, array $translations ) {
		foreach ( $settings as $key => &$value ) {
			$current = array_merge( $path, array( (string) $key ) );
			if ( is_array( $value ) ) {
				foreach ( $value as $row => &$nested ) {
					if ( ! is_array( $nested ) ) { continue; }
					$row_id = sanitize_key( (string) ( $nested['_id'] ?? $row ) );
					self::apply_settings( $nested, $element_id, array_merge( $current, array( $row_id ) ), $translations );
				}
				unset( $nested );
				continue;
			}
			$id = self::segment_id( $element_id, $current );
			if ( ! array_key_exists( $id, $translations ) || ! self::is_translatable( $key, $value ) ) { continue; }
			$value = self::is_html( $value ) ? wp_kses_post( $translations[ $id ] ) : sanitize_textarea_field( $translations[ $id ] );
		}
		unset( $value );
	}

	private static function segment_id( $element_id, array $path ) {
		return 'elementor_' . $element_id . '_' . substr( hash( 'sha256', implode( '/', $path ) ), 0, 16 );
	}

	private static function is_translatable( $key, $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) || ! preg_match( '/[\p{L}]/u', wp_strip_all_tags( $value ) ) ) { return false; }
		$key = strtolower( (string) $key );
		if ( preg_match( '/(?:url|link|image|icon|color|font|size|width|height|align|position|animation|css|class|id|query|taxonomy|template|media|background|border|margin|padding|spacing|unit|typography|weight|transform|style|responsive|motion|condition)/', $key ) ) { return false; }
		if ( preg_match( '~^(?:https?:|mailto:|tel:|/|#)~i', trim( $value ) ) ) { return false; }
		return (bool) apply_filters( 'openlingua_elementor_setting_is_translatable', true, $key, $value );
	}

	private static function is_html( $value ) {
		return (string) $value !== wp_strip_all_tags( (string) $value );
	}
}
