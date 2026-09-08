<?php
// Regression tests for translated taxonomy identities, parents and proper names.
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	final class WP_Error {
		private $message;
		public function __construct( $code = '', $message = '' ) { unset( $code ); $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
	$GLOBALS['openlingua_terms'] = array(
		1 => (object) array( 'term_id' => 1, 'taxonomy' => 'location', 'name' => 'Germany', 'slug' => 'germany', 'description' => '', 'parent' => 0 ),
		2 => (object) array( 'term_id' => 2, 'taxonomy' => 'location', 'name' => 'Bavaria', 'slug' => 'bavaria', 'description' => '', 'parent' => 1 ),
		3 => (object) array( 'term_id' => 3, 'taxonomy' => 'location', 'name' => 'Munich', 'slug' => 'munich', 'description' => '', 'parent' => 2 ),
		4 => (object) array( 'term_id' => 4, 'taxonomy' => 'location', 'name' => 'Managua', 'slug' => 'managua', 'description' => '', 'parent' => 0 ),
		5 => (object) array( 'term_id' => 5, 'taxonomy' => 'location', 'name' => 'León', 'slug' => 'leon', 'description' => '', 'parent' => 1 ),
		6 => (object) array( 'term_id' => 6, 'taxonomy' => 'location', 'name' => 'Venice', 'slug' => 'venice', 'description' => '', 'parent' => 0 ),
		7 => (object) array( 'term_id' => 7, 'taxonomy' => 'location', 'name' => 'Orphaned child', 'slug' => 'orphaned-child', 'description' => '', 'parent' => 999 ),
		205 => (object) array( 'term_id' => 205, 'taxonomy' => 'location', 'name' => 'León', 'slug' => 'leon-es', 'description' => '', 'parent' => 0 ),
		206 => (object) array( 'term_id' => 206, 'taxonomy' => 'location', 'name' => 'Venecia', 'slug' => 'venecia', 'description' => '', 'parent' => 0 ),
	);
	$GLOBALS['openlingua_term_meta'] = array( 3 => array( 'country_code' => array( 'DE' ) ) );
	$GLOBALS['openlingua_posts'] = array(
		11 => (object) array( 'ID' => 11, 'post_type' => 'listing' ),
		12 => (object) array( 'ID' => 12, 'post_type' => 'listing' ),
		13 => (object) array( 'ID' => 13, 'post_type' => 'listing' ),
		14 => (object) array( 'ID' => 14, 'post_type' => 'listing' ),
	);
	$GLOBALS['openlingua_object_terms'] = array(
		11 => array( 'location' => array( 3, 4 ) ),
		12 => array( 'location' => array( 3, 4 ) ),
		13 => array( 'location' => array( 6 ) ),
		14 => array( 'location' => array() ),
	);
	$GLOBALS['openlingua_next_term_id'] = 100;
	function __( $text ) { return $text; }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
	function absint( $value ) { return abs( (int) $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_generate_password() { return 'fallback'; }
	function get_term( $term_id, $taxonomy = '' ) { $term = $GLOBALS['openlingua_terms'][ $term_id ] ?? false; return $term && ( ! $taxonomy || $taxonomy === $term->taxonomy ) ? $term : false; }
	function get_post( $post_id ) { return $GLOBALS['openlingua_posts'][ $post_id ] ?? null; }
	function get_object_taxonomies( $post_type ) { return 'listing' === $post_type ? array( 'location' ) : array(); }
	function wp_get_object_terms( $post_id, $taxonomy ) { return $GLOBALS['openlingua_object_terms'][ $post_id ][ $taxonomy ] ?? array(); }
	function wp_set_object_terms( $post_id, $terms, $taxonomy ) { $GLOBALS['openlingua_object_terms'][ $post_id ][ $taxonomy ] = array_map( 'intval', (array) $terms ); return $terms; }
	function get_term_meta( $term_id ) { return $GLOBALS['openlingua_term_meta'][ $term_id ] ?? array(); }
	function add_term_meta( $term_id, $key, $value ) { $GLOBALS['openlingua_term_meta'][ $term_id ][ $key ][] = $value; }
	function maybe_unserialize( $value ) { return $value; }
	function wp_insert_term( $name, $taxonomy, $args ) {
		$id = ++$GLOBALS['openlingua_next_term_id'];
		$GLOBALS['openlingua_terms'][ $id ] = (object) array( 'term_id' => $id, 'taxonomy' => $taxonomy, 'name' => $name, 'slug' => $args['slug'], 'description' => $args['description'], 'parent' => $args['parent'] );
		$GLOBALS['wpdb']->slug_ids[ $args['slug'] ] = $id;
		return array( 'term_id' => $id );
	}
	function wp_update_term( $term_id, $taxonomy, $args ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term ) { return new WP_Error( 'not_found', 'Term not found' ); }
		foreach ( $args as $key => $value ) { $term->{$key} = $value; }
		return array( 'term_id' => $term_id );
	}
	final class Taxonomy_Sync_Test_DB {
		public $terms = 'wp_terms';
		public $term_taxonomy = 'wp_term_taxonomy';
		public $term_relationships = 'wp_term_relationships';
		public $slug_ids = array( 'germany' => 1, 'bavaria' => 2, 'munich' => 3, 'managua' => 4, 'leon' => 5, 'leon-es' => 205 );
		public function prepare( $query, ...$values ) { foreach ( $values as $value ) { $query = preg_replace( '/%[isd]/', is_numeric( $value ) ? (string) $value : "'" . $value . "'", $query, 1 ); } return $query; }
		public function get_var( $query ) {
			if ( preg_match( "/t\\.slug = '([^']+)'/", $query, $matches ) ) { return $this->slug_ids[ $matches[1] ] ?? 0; }
			if ( preg_match( '/term_id = (\\d+)/', $query, $matches ) ) { return (int) $matches[1]; }
			return 0;
		}
		public function get_col( $query ) {
			if ( preg_match( '/term_taxonomy_id = (\\d+)/', $query, $term_taxonomy ) ) {
				$objects = array();
				foreach ( $GLOBALS['openlingua_object_terms'] as $object_id => $taxonomies ) {
					foreach ( $taxonomies as $terms ) {
						if ( in_array( (int) $term_taxonomy[1], array_map( 'intval', $terms ), true ) ) { $objects[] = $object_id; }
					}
				}
				return $objects;
			}
			if ( ! preg_match( '/object_id = (\\d+)/', $query, $post ) ) { return null; }
			$terms = $GLOBALS['openlingua_object_terms'][ (int) $post[1] ] ?? array();
			if ( false !== strpos( $query, 'DISTINCT tt.taxonomy' ) ) { return array_keys( $terms ); }
			return preg_match( "/tt\\.taxonomy = '([^']+)'/", $query, $taxonomy ) ? ( $terms[ $taxonomy[1] ] ?? array() ) : array();
		}
	}
	$wpdb = new Taxonomy_Sync_Test_DB();
}

namespace OpenLingua {
	final class Languages {
		public static function is_valid( $language ) { return in_array( $language, array( 'en', 'de', 'es' ), true ); }
		public static function default_code() { return 'en'; }
	}
	final class Translations {
		public static $rows = array(
		1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 7 => null,
		205 => null, 206 => null,
		);
		public static function row( $type, $id ) { unset( $type ); return self::$rows[ $id ] ?? null; }
		public static function assign( $type, $id, $language, $group = '', $source_language = '' ) {
			unset( $type );
			$group = $group ?: 'group-' . $id;
			self::$rows[ $id ] = (object) array( 'group_uuid' => $group, 'language' => $language, 'source_language' => $source_language );
			return $group;
		}
		public static function translated_id( $type, $id, $language ) {
			unset( $type ); $source = self::row( 'term', $id );
			if ( ! $source ) { return 0; }
			foreach ( self::$rows as $candidate_id => $candidate ) { if ( $candidate && $candidate->group_uuid === $source->group_uuid && $candidate->language === $language ) { return $candidate_id; } }
			return 0;
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/class-taxonomies.php';
	foreach ( array( 1, 2, 3, 4, 5, 6 ) as $id ) { \OpenLingua\Translations::assign( 'term', $id, 'en', 'group-' . $id ); }
	\OpenLingua\Translations::assign( 'term', 205, 'es', 'group-5', 'en' );
	\OpenLingua\Translations::assign( 'term', 206, 'es', 'group-206', 'en' );
	\OpenLingua\Translations::assign( 'post', 13, 'en', 'post-group-13' );
	\OpenLingua\Translations::assign( 'post', 14, 'es', 'post-group-13', 'en' );
	function taxonomy_sync_assert( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } echo "PASS: {$message}\n"; }

	$munich_es = \OpenLingua\Taxonomies::ensure_translation( 3, 'es' );
	taxonomy_sync_assert( 103 === $munich_es, 'creates a translated term for a post assignment instead of reusing the source term ID' );
	taxonomy_sync_assert( 101 === $GLOBALS['openlingua_terms'][102]->parent && 102 === $GLOBALS['openlingua_terms'][103]->parent, 'creates the translated geographic parent hierarchy in the target language' );
	taxonomy_sync_assert( 'Munich' === $GLOBALS['openlingua_terms'][103]->name, 'preserves a geographic proper name until an editor supplies a localized name' );
	taxonomy_sync_assert( array( 'DE' ) === $GLOBALS['openlingua_term_meta'][103]['country_code'], 'copies shared geographic metadata to the created term translation' );

	$managua_es = \OpenLingua\Taxonomies::ensure_translation( 4, 'es' );
	taxonomy_sync_assert( 'Managua' === $GLOBALS['openlingua_terms'][ $managua_es ]->name && 'group-4' === \OpenLingua\Translations::row( 'term', $managua_es )->group_uuid, 'keeps identical location names as linked translations of one entity' );

	$leon_es = \OpenLingua\Taxonomies::ensure_translation( 5, 'es' );
	taxonomy_sync_assert( 205 === $leon_es && 101 === $GLOBALS['openlingua_terms'][205]->parent, 'repairs an existing translated child to use its translated parent' );

	$adopted = \OpenLingua\Taxonomies::link_existing_translation( 6, 206, 'es' );
	taxonomy_sync_assert( true === $adopted && 206 === \OpenLingua\Translations::translated_id( 'term', 6, 'es' ), 'links an independently created term to its explicit original without guessing from its name or slug' );
	$sync_term_relationships = new ReflectionMethod( \OpenLingua\Taxonomies::class, 'synchronize_term_translation_relationships' );
	$sync_term_relationships->setAccessible( true );
	$sync_term_relationships->invoke( null, 6, 206, 'es' );
	taxonomy_sync_assert( array( 206 ) === $GLOBALS['openlingua_object_terms'][14]['location'], 'syncs existing translated posts when a term translation is saved later' );

	$orphaned_parent_es = \OpenLingua\Taxonomies::ensure_translation( 7, 'es' );
	taxonomy_sync_assert( $orphaned_parent_es && 0 === $GLOBALS['openlingua_terms'][ $orphaned_parent_es ]->parent, 'keeps a valid term translatable when its historical parent was deleted' );

	$sync = \OpenLingua\Taxonomies::synchronize_post_terms( 11, 12, 'es' );
	taxonomy_sync_assert( true === $sync && array( 103, 104 ) === $GLOBALS['openlingua_object_terms'][12]['location'], 'replaces legacy source-language relationships on a translated post with matching target-language terms' );

	$GLOBALS['openlingua_object_terms'][11]['location'] = array( 3, 999 );
	$sync_with_orphan = \OpenLingua\Taxonomies::synchronize_post_terms( 11, 12, 'es' );
	taxonomy_sync_assert( true === $sync_with_orphan && array( 103 ) === $GLOBALS['openlingua_object_terms'][12]['location'], 'ignores an orphaned source relationship while synchronizing valid terms' );

	echo "All OpenLingua taxonomy synchronization tests passed.\n";
}
