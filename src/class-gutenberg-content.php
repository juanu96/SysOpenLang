<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

/** Extracts translatable Gutenberg block values without exposing block markup. */
final class Gutenberg_Content {
	const SOURCE_SNAPSHOT_META = '_openlingua_gutenberg_source_snapshot';
	private static $excluded_attributes = array(
		'id', 'ids', 'ref', 'url', 'href', 'src', 'link', 'anchor', 'class', 'classname',
		'style', 'styles', 'css', 'color', 'backgroundcolor', 'textcolor', 'gradient',
		'fontfamily', 'font-size', 'fontsize', 'align', 'layout', 'width', 'height',
		'mediaid', 'media_id', 'attachmentid', 'attachment_id', 'rel', 'target', 'type',
		'level', 'tagname', 'lock', 'metadata', 'namespace', 'query', 'termid', 'postid',
	);

	public static function hooks() {
		add_filter( 'render_block', array( __CLASS__, 'translate_dynamic_output' ), 20, 2 );
	}

	public static function translate_dynamic_output( $content, $block ) {
		$name = sanitize_key( (string) ( $block['blockName'] ?? '' ) );
		if ( '' === $name || ! class_exists( 'WP_Block_Type_Registry' ) ) { return $content; }
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] );
		if ( ! $type || ! $type->is_dynamic() ) { return $content; }
		return wp_kses_post( Shortcode_Content::translate_html( $content, 'block-' . $name ) );
	}

	public static function is_gutenberg( $content ) {
		return function_exists( 'has_blocks' ) ? has_blocks( (string) $content ) : false !== strpos( (string) $content, '<!-- wp:' );
	}

	public static function extract( $content ) {
		if ( ! self::is_gutenberg( $content ) || ! function_exists( 'parse_blocks' ) ) { return array(); }
		$segments = array();
		self::walk_extract( parse_blocks( (string) $content ), array(), $segments );
		return $segments;
	}

	public static function values( $content ) {
		$values = array();
		foreach ( self::extract( $content ) as $segment ) { $values[ $segment['id'] ] = $segment['value']; }
		return $values;
	}

	public static function source_snapshot( $content ) {
		$segments = array();
		foreach ( self::extract( $content ) as $segment ) {
			$segments[ $segment['id'] ] = array(
				'hash' => self::segment_hash( $segment ),
				'compatibility' => self::compatibility_key( $segment ),
				'value' => self::normalized_value( $segment['value'], $segment['format'] ),
			);
		}
		return array( 'version' => 1, 'segments' => $segments );
	}

	public static function aligned_values( $source_content, $target_content, array $snapshot = array() ) {
		$source_segments = self::extract( $source_content );
		$target_values = self::values( $target_content );
		$previous = 1 === (int) ( $snapshot['version'] ?? 0 ) && is_array( $snapshot['segments'] ?? null ) ? $snapshot['segments'] : array();
		if ( ! $previous ) { return $target_values; }
		$pools = array();
		foreach ( $previous as $old_id => $descriptor ) {
			if ( isset( $descriptor['compatibility'], $descriptor['value'] ) ) { $pools[ $descriptor['compatibility'] . ':' . $descriptor['value'] ][] = (string) $old_id; }
		}
		$aligned = array();
		$used = array();
		foreach ( $source_segments as $segment ) {
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

	public static function apply( $content, array $translations, $target_language = '' ) {
		if ( ! self::is_gutenberg( $content ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) { return (string) $content; }
		$blocks = parse_blocks( (string) $content );
		self::walk_apply( $blocks, array(), $translations, sanitize_key( $target_language ) );
		return serialize_blocks( $blocks );
	}

	private static function walk_extract( array $blocks, array $parent_path, array &$segments ) {
		foreach ( $blocks as $index => $block ) {
			$path = array_merge( $parent_path, array( $index ) );
			$name = (string) ( $block['blockName'] ?? '' );
			$name = $name ?: 'core/freeform';
			$label = self::block_label( $name, $path );
			self::extract_attributes( (array) ( $block['attrs'] ?? array() ), array(), $path, $name, $label, $segments );
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $fragment_index => $fragment ) {
				if ( ! is_string( $fragment ) ) { continue; }
				$segments = array_merge( $segments, self::content_segments( $fragment, $name, $path, $fragment_index, $label ) );
			}
			self::walk_extract( (array) ( $block['innerBlocks'] ?? array() ), $path, $segments );
		}
	}

	private static function extract_attributes( array $attributes, array $attribute_path, array $block_path, $block_name, $label, array &$segments ) {
		foreach ( $attributes as $key => $value ) {
			$path = array_merge( $attribute_path, array( $key ) );
			if ( is_array( $value ) ) {
				self::extract_attributes( $value, $path, $block_path, $block_name, $label, $segments );
				continue;
			}
			if ( ! is_string( $value ) || ! self::is_translatable_attribute( $key, $value ) ) { continue; }
			$segments[] = array(
				'id' => self::segment_id( $block_path, 'attribute', $path ),
				'label' => $label . ' — ' . self::attribute_label( $key ),
				'value' => $value,
				'format' => self::looks_like_html( $value ) ? 'html' : 'text',
				'kind' => 'attribute',
				'block_name' => $block_name,
				'block_path' => $block_path,
				'value_path' => $path,
			);
		}
	}

	private static function walk_apply( array &$blocks, array $parent_path, array $translations, $target_language ) {
		foreach ( $blocks as $index => &$block ) {
			$path = array_merge( $parent_path, array( $index ) );
			$name = (string) ( $block['blockName'] ?? '' );
			$name = $name ?: 'core/freeform';
			if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) { $block['attrs'] = array(); }
			if ( ! isset( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) { $block['innerBlocks'] = array(); }
			if ( ! isset( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) { $block['innerContent'] = array(); }
			self::apply_attributes( $block['attrs'], array(), $path, $translations );
			if ( $target_language ) { self::map_block_references( $name, $block['attrs'], $target_language ); }
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $fragment_index => $fragment ) {
				if ( ! is_string( $fragment ) ) { continue; }
				$label = self::block_label( $name, $path );
				$replacements = array();
				foreach ( self::content_segments( $fragment, $name, $path, $fragment_index, $label ) as $segment ) {
					if ( ! array_key_exists( $segment['id'], $translations ) ) { continue; }
					$value = (string) $translations[ $segment['id'] ];
					$replacements[] = array( 'offset' => $segment['offset'], 'length' => $segment['length'], 'value' => 'html' === $segment['format'] ? wp_kses_post( $value ) : esc_html( sanitize_textarea_field( $value ) ) );
				}
				usort( $replacements, function ( $left, $right ) { return $right['offset'] <=> $left['offset']; } );
				foreach ( $replacements as $replacement ) { $fragment = substr_replace( $fragment, $replacement['value'], $replacement['offset'], $replacement['length'] ); }
				$block['innerContent'][ $fragment_index ] = $target_language ? self::map_html_links( $fragment, $target_language ) : $fragment;
			}
			self::walk_apply( $block['innerBlocks'], $path, $translations, $target_language );
		}
		unset( $block );
	}

	private static function map_block_references( $name, array &$attributes, $target_language ) {
		if ( in_array( $name, array( 'core/block', 'core/navigation' ), true ) && ! empty( $attributes['ref'] ) ) {
			$translated = Translations::translated_id_with_fallback( 'post', absint( $attributes['ref'] ), $target_language );
			if ( $translated && 'publish' === get_post_status( $translated ) ) { $attributes['ref'] = absint( $translated ); }
		}
		if ( 'core/navigation-link' === $name && ! empty( $attributes['id'] ) ) {
			$element_type = 'taxonomy' === ( $attributes['kind'] ?? '' ) ? 'term' : 'post';
			$translated = Translations::translated_id_with_fallback( $element_type, absint( $attributes['id'] ), $target_language );
			if ( $translated ) {
				$attributes['id'] = absint( $translated );
				if ( 'term' === $element_type && function_exists( 'get_term_link' ) ) {
					$url = get_term_link( $translated, sanitize_key( $attributes['type'] ?? '' ) );
					if ( ! is_wp_error( $url ) ) { $attributes['url'] = $url; }
				} elseif ( 'publish' === get_post_status( $translated ) ) {
					$url = get_permalink( $translated );
					if ( $url ) { $attributes['url'] = $url; }
				}
			}
		}
		self::map_url_attributes( $attributes, $target_language );
	}

	private static function map_url_attributes( array &$attributes, $target_language ) {
		foreach ( $attributes as $key => &$value ) {
			if ( is_array( $value ) ) { self::map_url_attributes( $value, $target_language ); continue; }
			if ( is_string( $value ) && preg_match( '/(?:url|href|link)$/i', (string) $key ) ) { $value = self::map_internal_url( $value, $target_language ); }
		}
		unset( $value );
	}

	private static function map_html_links( $html, $target_language ) {
		return preg_replace_callback( '/\bhref=((["\'])(.*?)\2)/i', static function ( $match ) use ( $target_language ) {
			return 'href=' . $match[2] . esc_url( self::map_internal_url( html_entity_decode( $match[3], ENT_QUOTES, 'UTF-8' ), $target_language ) ) . $match[2];
		}, (string) $html );
	}

	public static function map_internal_url( $url, $target_language ) {
		$url = (string) $url;
		if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '~^(?:mailto:|tel:|javascript:)~i', $url ) ) { return $url; }
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $url_host && $home_host && strtolower( $url_host ) !== strtolower( $home_host ) ) { return $url; }
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) { return $url; }
		$translated_id = Translations::translated_id_with_fallback( 'post', $post_id, $target_language );
		if ( ! $translated_id || 'publish' !== get_post_status( $translated_id ) ) { return $url; }
		$translated_url = get_permalink( $translated_id );
		if ( ! $translated_url ) { return $url; }
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
		return $translated_url . ( $query ? '?' . $query : '' ) . ( $fragment ? '#' . $fragment : '' );
	}

	private static function apply_attributes( array &$attributes, array $attribute_path, array $block_path, array $translations ) {
		foreach ( $attributes as $key => &$value ) {
			$path = array_merge( $attribute_path, array( $key ) );
			if ( is_array( $value ) ) { self::apply_attributes( $value, $path, $block_path, $translations ); continue; }
			$id = self::segment_id( $block_path, 'attribute', $path );
			if ( ! is_string( $value ) || ! array_key_exists( $id, $translations ) ) { continue; }
			$translated = (string) $translations[ $id ];
			$value = self::looks_like_html( $value ) ? wp_kses_post( $translated ) : sanitize_textarea_field( $translated );
		}
		unset( $value );
	}

	private static function is_translatable_attribute( $key, $value ) {
		$key = strtolower( (string) $key );
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/\p{L}/u', html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) ) ) { return false; }
		if ( in_array( $key, self::$excluded_attributes, true ) || preg_match( '/(?:^|_)(?:url|uri|href|src|id|ids|class|style|color|size|width|height|align|layout|slug|key|token|icon)(?:$|_)/i', $key ) ) { return false; }
		if ( preg_match( '~^(?:https?:)?//|^mailto:|^tel:|^#?[0-9a-f]{3,8}$~i', $value ) ) { return false; }
		if ( preg_match( '/(?:text|content|title|heading|caption|description|label|placeholder|message|button|summary|citation|author|name|alt|aria)/i', $key ) ) { return true; }
		return self::looks_like_html( $value ) || preg_match( '/\s|[.!?,;:¿¡]/u', $value );
	}

	private static function has_visible_text( $html ) {
		return '' !== trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	private static function content_segments( $fragment, $block_name, array $block_path, $fragment_index, $label ) {
		if ( ! self::has_visible_text( $fragment ) ) { return array(); }
		if ( 'core/table' !== $block_name ) {
			return array( array(
				'id' => self::segment_id( $block_path, 'content', array( $fragment_index ) ),
				'label' => $label . ' — ' . __( 'Content', 'sysopenlang' ),
				'value' => $fragment, 'format' => 'html', 'kind' => 'content',
				'block_name' => $block_name,
				'block_path' => $block_path, 'value_path' => array( $fragment_index ),
				'offset' => 0, 'length' => strlen( $fragment ),
			) );
		}

		preg_match_all( '~<(th|td|caption)\b[^>]*>(.*?)</\1\s*>~is', $fragment, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$segments = array();
		$counts = array( 'th' => 0, 'td' => 0, 'caption' => 0 );
		foreach ( $matches as $match ) {
			$tag = strtolower( $match[1][0] );
			$value = $match[2][0];
			if ( ! self::has_visible_text( $value ) ) { continue; }
			$counts[ $tag ]++;
			$field = 'th' === $tag ? __( 'Header cell', 'sysopenlang' ) : ( 'caption' === $tag ? __( 'Caption', 'sysopenlang' ) : __( 'Cell', 'sysopenlang' ) );
			$segments[] = array(
				'id' => self::segment_id( $block_path, 'content', array( $fragment_index, $tag, $counts[ $tag ] ) ),
				'label' => $label . ' — ' . $field . ' ' . $counts[ $tag ],
				'value' => $value,
				'format' => self::looks_like_html( $value ) ? 'html' : 'text',
				'kind' => 'content', 'block_path' => $block_path,
				'block_name' => $block_name,
				'value_path' => array( $fragment_index, $tag, $counts[ $tag ] ),
				'offset' => $match[2][1], 'length' => strlen( $value ),
			);
		}
		return $segments;
	}

	private static function looks_like_html( $value ) {
		return (bool) preg_match( '/<\/?[a-z][^>]*>/i', (string) $value );
	}

	private static function compatibility_key( array $segment ) {
		$path = (array) ( $segment['value_path'] ?? array() );
		$field = $path ? (string) end( $path ) : (string) ( $segment['kind'] ?? '' );
		return (string) ( $segment['block_name'] ?? '' ) . ':' . (string) ( $segment['kind'] ?? '' ) . ':' . sanitize_key( $field );
	}

	private static function normalized_value( $value, $format ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $value );
		return 'html' === $format ? trim( preg_replace( '/>\s+</u', '><', $value ) ) : trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	private static function segment_hash( array $segment ) {
		return hash( 'sha256', self::compatibility_key( $segment ) . "\n" . self::normalized_value( $segment['value'], $segment['format'] ) );
	}

	private static function segment_id( array $block_path, $kind, array $value_path ) {
		return sanitize_key( 'gutenberg_' . implode( '_', array_map( 'absint', $block_path ) ) . '_' . $kind . '_' . implode( '_', array_map( 'sanitize_key', $value_path ) ) );
	}

	private static function block_label( $name, array $path ) {
		$title = ucwords( str_replace( array( '/', '-', '_' ), ' ', preg_replace( '/^core\//', '', $name ) ) );
		return sprintf( '%s %s', $title, implode( '.', array_map( function ( $part ) { return absint( $part ) + 1; }, $path ) ) );
	}

	private static function attribute_label( $key ) {
		return ucwords( trim( preg_replace( '/([a-z])([A-Z])/', '$1 $2', str_replace( array( '_', '-' ), ' ', (string) $key ) ) ) );
	}
}
