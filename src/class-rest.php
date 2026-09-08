<?php
namespace OpenLingua;

defined( 'ABSPATH' ) || exit;

final class REST {
	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'openlingua/v1', '/languages', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'languages' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'openlingua/v1', '/translations/(?P<type>post|term)/(?P<id>\d+)', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'translations' ),
			'permission_callback' => '__return_true',
			'args' => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( 'openlingua/v1', '/jobs', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( __CLASS__, 'create_job' ),
			'permission_callback' => function ( $request ) { return current_user_can( 'openlingua_translate' ) && current_user_can( 'edit_post', absint( $request['source_id'] ) ) && current_user_can( 'edit_post', absint( $request['target_id'] ) ); },
			'args' => array(
				'source_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'target_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'target_language' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				'provider' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
		register_rest_route( 'openlingua/v1', '/diagnostics', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => function () { return rest_ensure_response( \OpenLingua\Modules\Diagnostics::report() ); },
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		) );
	}

	public static function languages() {
		$result = array();
		foreach ( Languages::public_all() as $code => $language ) {
			$result[] = array( 'code' => $code, 'name' => $language['name'], 'locale' => $language['locale'], 'default' => Languages::default_code() === $code );
		}
		return rest_ensure_response( $result );
	}

	public static function translations( \WP_REST_Request $request ) {
		$type  = sanitize_key( $request['type'] );
		$id    = absint( $request['id'] );
		$items = array();
		foreach ( Translations::group( $type, $id ) as $language => $element_id ) {
			if ( 'post' === $type ) {
				if ( ! is_post_publicly_viewable( $element_id ) && ! current_user_can( 'edit_post', $element_id ) ) { continue; }
				$url = get_permalink( $element_id );
			} else {
				$term = get_term( $element_id );
				$taxonomy = $term && ! is_wp_error( $term ) ? get_taxonomy( $term->taxonomy ) : null;
				if ( ! $taxonomy || ( ! $taxonomy->public && ! current_user_can( $taxonomy->cap->manage_terms ) ) ) { continue; }
				$url = get_term_link( $term );
			}
			// A term relationship is still useful to headless consumers when another
			// plugin prevents WordPress from generating its permalink in this request.
			// Never hide the translation ID: clients can query that term directly and
			// a subsequent normal frontend request can resolve its public URL.
			$items[ $language ] = array( 'id' => absint( $element_id ), 'url' => is_wp_error( $url ) ? '' : $url );
		}
		return rest_ensure_response( array( 'type' => $type, 'source_id' => $id, 'translations' => $items ) );
	}

	public static function create_job( \WP_REST_Request $request ) {
		$result = \OpenLingua\Modules\Jobs::enqueue( absint( $request['source_id'] ), absint( $request['target_id'] ), sanitize_key( $request['target_language'] ), sanitize_key( $request['provider'] ) );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'job_id' => $result, 'status' => 'pending' ), 201 );
	}
}
