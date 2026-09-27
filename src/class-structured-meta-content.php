<?php
namespace SysOpenLang;

use SysOpenLang\Contracts\Content_Extractor;

defined( 'ABSPATH' ) || exit;

/** Safely translates builder documents stored as JSON or nested meta arrays. */
final class Structured_Meta_Content implements Content_Extractor {
	public function id() { return 'structured-meta'; }
	public function label() { return __( 'Visual builder content', 'sysopenlang' ); }
	public function supports( $post ) { return (bool) self::documents( $post ); }

	public function extract( $post ) {
		$segments = array();
		foreach ( self::documents( $post ) as $key => $document ) { self::walk( $document['data'], $key, array(), $segments ); }
		return array_values( $segments );
	}

	public function values( $post ) {
		$values = array();
		foreach ( $this->extract( $post ) as $segment ) { $values[ $segment['id'] ] = $segment['value']; }
		return $values;
	}

	public function apply( $source, $target, array $translations, $target_language = '' ) {
		unset( $target_language );
		foreach ( self::documents( $source ) as $key => $document ) {
			$data = $document['data'];
			self::apply_node( $data, $key, array(), $translations );
			$value = 'json' === $document['format'] ? wp_json_encode( $data ) : $data;
			update_post_meta( $target->ID, $key, $value );
		}
	}

	public static function inspect( $post ) {
		$post = get_post( $post );
		if ( ! $post ) { return array(); }
		$report = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			$reason = self::is_acf_meta( $post->ID, $key ) ? 'acf-field' : ( self::excluded_key( $key ) ? 'excluded-key' : 'not-a-builder-document' );
			if ( 'acf-field' === $reason || 'excluded-key' === $reason ) { $report[ $key ] = $reason; continue; }
			foreach ( (array) $values as $raw ) {
				$decoded = self::decode( $raw );
				if ( $decoded && self::looks_like_builder_document( $decoded['data'] ) ) { $reason = 'translatable-document'; break; }
				if ( is_object( maybe_unserialize( $raw ) ) ) { $reason = 'opaque-object'; }
			}
			$report[ $key ] = $reason;
		}
		return (array) apply_filters( 'openlingua_structured_meta_inspection', $report, $post );
	}

	private static function documents( $post ) {
		$post = get_post( $post );
		if ( ! $post ) { return array(); }
		$documents = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( self::excluded_key( $key ) || self::is_acf_meta( $post->ID, $key ) ) { continue; }
			foreach ( (array) $values as $raw ) {
				$decoded = self::decode( $raw );
				if ( $decoded && self::looks_like_builder_document( $decoded['data'] ) ) { $documents[ $key ] = $decoded; break; }
			}
		}
		return (array) apply_filters( 'openlingua_structured_meta_documents', $documents, $post );
	}

	private static function decode( $raw ) {
		$value = maybe_unserialize( $raw );
		if ( is_array( $value ) ) { return array( 'format' => 'array', 'data' => $value ); }
		if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; }
		$json = json_decode( wp_unslash( $value ), true );
		return is_array( $json ) ? array( 'format' => 'json', 'data' => $json ) : null;
	}

	private static function looks_like_builder_document( array $data ) {
		return self::structure_score( $data ) >= 3 && self::has_stable_node( $data );
	}

	private static function has_stable_node( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), array( 'id', '_id', 'uid', 'uuid' ), true ) && is_scalar( $value ) && '' !== (string) $value ) { return true; }
			if ( is_array( $value ) && self::has_stable_node( $value ) ) { return true; }
		}
		return false;
	}

	private static function structure_score( array $data ) {
		$score = 0;
		foreach ( $data as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), array( 'id', '_id', 'settings', 'elements', 'children', 'content', 'widgettype', 'eltype' ), true ) ) { $score++; }
			if ( is_array( $value ) ) { $score += self::structure_score( $value ); }
		}
		return $score;
	}

	private static function walk( array $node, $meta_key, array $path, array &$segments ) {
		foreach ( $node as $key => $value ) {
			$part = is_array( $value ) ? self::node_identity( $value, $key ) : (string) $key;
			$current = array_merge( $path, array( $part ) );
			if ( is_array( $value ) ) { self::walk( $value, $meta_key, $current, $segments ); continue; }
			if ( ! self::is_translatable( $key, $value ) ) { continue; }
			$id = self::segment_id( $meta_key, $current );
			$segments[ $id ] = array( 'id' => $id, 'label' => ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) ), 'value' => (string) $value, 'format' => self::is_html( $value ) ? 'html' : 'text', 'meta_key' => $meta_key );
		}
	}

	private static function apply_node( array &$node, $meta_key, array $path, array $translations ) {
		foreach ( $node as $key => &$value ) {
			$part = is_array( $value ) ? self::node_identity( $value, $key ) : (string) $key;
			$current = array_merge( $path, array( $part ) );
			if ( is_array( $value ) ) { self::apply_node( $value, $meta_key, $current, $translations ); continue; }
			$id = self::segment_id( $meta_key, $current );
			if ( ! array_key_exists( $id, $translations ) || ! self::is_translatable( $key, $value ) ) { continue; }
			$value = self::is_html( $value ) ? wp_kses_post( $translations[ $id ] ) : sanitize_textarea_field( $translations[ $id ] );
		}
		unset( $value );
	}

	private static function node_identity( array $value, $fallback ) {
		foreach ( array( 'id', '_id', 'uid', 'uuid' ) as $key ) { if ( ! empty( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) { return sanitize_key( (string) $value[ $key ] ); } }
		return (string) $fallback;
	}

	private static function segment_id( $meta_key, array $path ) { return 'structured_' . substr( hash( 'sha256', $meta_key . '|' . implode( '/', $path ) ), 0, 24 ); }
	private static function is_html( $value ) { return (string) $value !== wp_strip_all_tags( (string) $value ); }

	private static function is_translatable( $key, $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) || ! preg_match( '/[\p{L}]/u', wp_strip_all_tags( $value ) ) ) { return false; }
		$key = strtolower( (string) $key );
		if ( preg_match( '/(?:url|href|src|image|icon|color|font|size|width|height|align|position|animation|css|class|id|type|query|taxonomy|template|media|background|border|margin|padding|spacing|unit|style|responsive|condition|code|script)/', $key ) ) { return false; }
		if ( preg_match( '~^(?:https?:|mailto:|tel:|/|#)~i', trim( $value ) ) ) { return false; }
		return (bool) apply_filters( 'openlingua_structured_meta_value_is_translatable', true, $key, $value );
	}

	private static function excluded_key( $key ) {
		$excluded = 0 === strpos( $key, '_openlingua_' ) || in_array( $key, array( '_elementor_data', '_edit_lock', '_edit_last' ), true ) || (bool) preg_match( '/(?:seo|cache|css|style|asset|revision|backup|history|settings)$/i', $key );
		return (bool) apply_filters( 'openlingua_structured_meta_key_excluded', $excluded, $key );
	}

	private static function is_acf_meta( $post_id, $key ) {
		if ( function_exists( 'acf_get_field' ) && acf_get_field( $key ) ) { return true; }
		if ( 0 === strpos( $key, '_' ) ) { return false; }
		$reference = get_post_meta( $post_id, '_' . $key, true );
		return is_string( $reference ) && 0 === strpos( $reference, 'field_' );
	}
}
