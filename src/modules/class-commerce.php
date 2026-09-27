<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Translations;

defined( 'ABSPATH' ) || exit;

final class Commerce implements Module {
	const SOURCE_VARIATION_META = '_openlingua_source_variation_id';

	private static $syncing = false;

	public static function hooks() {
		add_filter( 'openlingua_meta_policy', array( __CLASS__, 'meta_policy' ), 10, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'sync_product_data' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'sync_product_data' ), 20, 1 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'sync_variation_parent' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'sync_variation_parent' ), 20, 1 );
	}

	public static function meta_policy( $policy, $key, $post_type ) {
		if ( 'product' !== $post_type ) { return $policy; }
		if ( in_array( $key, array( '_sku', 'total_sales', '_wc_average_rating', '_wc_rating_count' ), true ) ) { return 'ignore'; }
		if ( in_array( $key, self::shared_keys(), true ) ) { return 'copy'; }
		return $policy;
	}

	public static function shared_keys() {
		return apply_filters( 'openlingua_woocommerce_shared_meta', array(
			'_regular_price', '_sale_price', '_price', '_stock', '_stock_status', '_manage_stock',
			'_backorders', '_tax_status', '_tax_class', '_virtual', '_downloadable', '_downloadable_files',
			'_download_limit', '_download_expiry', '_sold_individually', '_weight', '_length', '_width', '_height',
		) );
	}

	public static function sync_product_data( $post_id ) {
		if ( self::$syncing || wp_is_post_revision( $post_id ) ) { return; }
		$row = Translations::row( 'post', $post_id );
		if ( ! $row || ! function_exists( 'wc_get_product' ) ) { return; }
		self::$syncing = true;
		foreach ( Translations::group( 'post', $post_id ) as $translation_id ) {
			if ( absint( $translation_id ) !== absint( $post_id ) ) { self::synchronize_product( $post_id, $translation_id ); }
		}
		self::$syncing = false;
	}

	public static function sync_variation_parent( $variation_id ) {
		if ( self::$syncing ) { return; }
		$parent_id = wp_get_post_parent_id( absint( $variation_id ) );
		if ( $parent_id ) { self::sync_product_data( $parent_id ); }
	}

	public static function initialize_translation( $source_id, $target_id ) {
		if ( function_exists( 'wc_get_product' ) ) {
			self::$syncing = true;
			self::synchronize_product( $source_id, $target_id );
			self::$syncing = false;
		}
	}

	private static function synchronize_product( $source_id, $target_id ) {
		$source = wc_get_product( $source_id );
		$target = wc_get_product( $target_id );
		if ( ! $source || ! $target ) { return; }
		$setters = array(
			'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status',
			'backorders', 'tax_status', 'tax_class', 'virtual', 'downloadable', 'downloads',
			'download_limit', 'download_expiry', 'sold_individually', 'weight', 'length', 'width', 'height',
		);
		foreach ( $setters as $property ) {
			$getter = 'get_' . $property;
			$setter = 'set_' . $property;
			if ( is_callable( array( $source, $getter ) ) && is_callable( array( $target, $setter ) ) ) { $target->{$setter}( $source->{$getter}() ); }
		}
		$target_row = Translations::row( 'post', $target_id );
		$target_language = $target_row ? $target_row->language : '';
		if ( $target_language ) {
			if ( is_callable( array( $source, 'get_attributes' ) ) && is_callable( array( $target, 'set_attributes' ) ) ) { $target->set_attributes( self::translated_attributes( $source->get_attributes(), $target_language ) ); }
			if ( is_callable( array( $source, 'get_default_attributes' ) ) && is_callable( array( $target, 'set_default_attributes' ) ) ) { $target->set_default_attributes( self::translated_variation_attributes( $source->get_default_attributes(), $target_language ) ); }
			if ( is_callable( array( $source, 'get_upsell_ids' ) ) && is_callable( array( $target, 'set_upsell_ids' ) ) ) { $target->set_upsell_ids( self::translated_product_ids( $source->get_upsell_ids(), $target_language ) ); }
			if ( is_callable( array( $source, 'get_cross_sell_ids' ) ) && is_callable( array( $target, 'set_cross_sell_ids' ) ) ) { $target->set_cross_sell_ids( self::translated_product_ids( $source->get_cross_sell_ids(), $target_language ) ); }
		}
		$target->save();
		if ( $target_language && is_callable( array( $source, 'is_type' ) ) && $source->is_type( 'variable' ) ) { self::synchronize_variations( $source, $target, $target_language ); }
	}

	public static function translated_product_ids( array $product_ids, $target_language ) {
		$mapped = array();
		foreach ( $product_ids as $product_id ) {
			$translated_id = Translations::translated_id( 'post', absint( $product_id ), $target_language );
			if ( $translated_id && 'product' === get_post_type( $translated_id ) ) { $mapped[] = absint( $translated_id ); }
		}
		return array_values( array_unique( $mapped ) );
	}

	public static function translated_attributes( array $attributes, $target_language ) {
		$translated = array();
		foreach ( $attributes as $key => $attribute ) {
			if ( ! is_object( $attribute ) || ! is_callable( array( $attribute, 'get_options' ) ) ) { $translated[ $key ] = $attribute; continue; }
			$copy = clone $attribute;
			if ( is_callable( array( $copy, 'is_taxonomy' ) ) && $copy->is_taxonomy() ) {
				$options = array();
				foreach ( $copy->get_options() as $term_id ) {
					$translated_id = Translations::translated_id( 'term', absint( $term_id ), $target_language );
					$options[] = $translated_id ?: absint( $term_id );
				}
				$copy->set_options( array_values( array_unique( $options ) ) );
			}
			$translated[ $key ] = $copy;
		}
		return $translated;
	}

	public static function translated_variation_attributes( array $attributes, $target_language ) {
		$translated = array();
		foreach ( $attributes as $taxonomy => $value ) {
			$translated[ $taxonomy ] = self::translated_attribute_value( $taxonomy, $value, $target_language );
		}
		return $translated;
	}

	public static function translation_fields( $source_id, $target_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) { return array(); }
		$source = wc_get_product( $source_id );
		$target = wc_get_product( $target_id );
		if ( ! $source || ! $target || ! $source->is_type( 'variable' ) ) { return array(); }
		$targets = self::target_variations_by_source( $target );
		$fields = array();
		foreach ( (array) $source->get_children() as $source_variation_id ) {
			$source_variation = wc_get_product( $source_variation_id );
			$target_variation = ! empty( $targets[ $source_variation_id ] ) ? wc_get_product( $targets[ $source_variation_id ] ) : null;
			if ( ! $source_variation || ! $target_variation ) { continue; }
			$source_description = (string) $source_variation->get_description();
			$target_description = (string) $target_variation->get_description();
			if ( '' === trim( $source_description ) && '' === trim( $target_description ) ) { continue; }
			$fields[] = array(
				'id' => 'wc_variation_' . absint( $source_variation_id ) . '_description',
				'label' => sprintf( /* translators: %s: variation name. */ __( 'Variation description — %s', 'sysopenlang' ), $source_variation->get_name() ),
				'source' => $source_description,
				'target' => $target_description,
				'target_variation_id' => absint( $target_variation->get_id() ),
			);
		}
		return (array) apply_filters( 'openlingua_woocommerce_translation_fields', $fields, $source_id, $target_id );
	}

	public static function save_translation_fields( $source_id, $target_id, array $submitted ) {
		foreach ( self::translation_fields( $source_id, $target_id ) as $field ) {
			if ( ! array_key_exists( $field['id'], $submitted ) ) { continue; }
			$variation = wc_get_product( $field['target_variation_id'] );
			if ( ! $variation || ! is_callable( array( $variation, 'set_description' ) ) ) { continue; }
			$variation->set_description( wp_kses_post( $submitted[ $field['id'] ] ) );
			$variation->save();
		}
		do_action( 'openlingua_saved_woocommerce_translation_fields', $source_id, $target_id, $submitted );
	}

	private static function translated_attribute_value( $taxonomy, $value, $target_language ) {
		if ( ! taxonomy_exists( $taxonomy ) || '' === (string) $value ) { return $value; }
		$term = get_term_by( 'slug', $value, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) { return $value; }
		$translated_id = Translations::translated_id( 'term', $term->term_id, $target_language );
		if ( ! $translated_id ) { return $value; }
		$translated_term = get_term( $translated_id, $taxonomy );
		return $translated_term && ! is_wp_error( $translated_term ) ? $translated_term->slug : $value;
	}

	private static function synchronize_variations( $source, $target, $target_language ) {
		if ( ! class_exists( 'WC_Product_Variation' ) || ! is_callable( array( $source, 'get_children' ) ) ) { return; }
		$existing = self::target_variations_by_source( $target );
		$unlinked = array_values( array_diff( array_map( 'absint', (array) $target->get_children() ), array_values( $existing ) ) );
		foreach ( (array) $source->get_children() as $source_variation_id ) {
			$source_variation = wc_get_product( $source_variation_id );
			if ( ! $source_variation || ! $source_variation->is_type( 'variation' ) ) { continue; }
			$translated_attributes = self::translated_variation_attributes( $source_variation->get_attributes(), $target_language );
			$target_variation = ! empty( $existing[ $source_variation_id ] ) ? wc_get_product( $existing[ $source_variation_id ] ) : self::matching_unlinked_variation( $unlinked, $translated_attributes );
			if ( ! $target_variation ) { $target_variation = new \WC_Product_Variation(); }
			if ( ! $target_variation ) { continue; }
			$target_variation->set_parent_id( $target->get_id() );
			foreach ( array( 'status', 'menu_order', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'tax_status', 'tax_class', 'virtual', 'downloadable', 'downloads', 'download_limit', 'download_expiry', 'weight', 'length', 'width', 'height', 'image_id' ) as $property ) {
				$getter = 'get_' . $property;
				$setter = 'set_' . $property;
				if ( is_callable( array( $source_variation, $getter ) ) && is_callable( array( $target_variation, $setter ) ) ) { $target_variation->{$setter}( $source_variation->{$getter}() ); }
			}
			$target_variation->set_attributes( $translated_attributes );
			$target_variation->update_meta_data( self::SOURCE_VARIATION_META, absint( $source_variation_id ) );
			$target_variation->save();
		}
	}

	private static function matching_unlinked_variation( array &$variation_ids, array $attributes ) {
		ksort( $attributes );
		foreach ( $variation_ids as $index => $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) { continue; }
			$candidate = $variation->get_attributes();
			ksort( $candidate );
			if ( $candidate !== $attributes ) { continue; }
			unset( $variation_ids[ $index ] );
			return $variation;
		}
		return null;
	}

	private static function target_variations_by_source( $target ) {
		$existing = array();
		foreach ( (array) $target->get_children() as $target_variation_id ) {
			$source_variation_id = absint( get_post_meta( $target_variation_id, self::SOURCE_VARIATION_META, true ) );
			if ( $source_variation_id ) { $existing[ $source_variation_id ] = absint( $target_variation_id ); }
		}
		return $existing;
	}
}
