<?php
namespace OpenLingua\Modules;

use OpenLingua\Contracts\Module;

defined( 'ABSPATH' ) || exit;

final class CLI implements Module {
	public static function hooks() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) { \WP_CLI::add_command( 'openlingua', CLI_Command::class ); }
	}
}

class CLI_Command {
	/** Lists configured languages. */
	public function languages() {
		\WP_CLI\Utils\format_items( 'table', array_map( function ( $code, $item ) { return array( 'code' => $code, 'name' => $item['name'], 'locale' => $item['locale'] ); }, array_keys( \OpenLingua\Languages::all() ), array_values( \OpenLingua\Languages::all() ) ), array( 'code', 'name', 'locale' ) );
	}

	/** Exports a portable JSON snapshot. */
	public function export( $args, $assoc_args ) {
		if ( isset( $assoc_args['file'] ) ) {
			\WP_CLI::error( __( 'The --file option is no longer supported. Redirect the JSON output instead: wp openlingua export > openlingua-export.json', 'openlingua' ) );
		}

		$json = wp_json_encode( Portability::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			\WP_CLI::error( __( 'Could not encode the OpenLingua export.', 'openlingua' ) );
		}

		// Keep stdout machine-readable. The shell, not the plugin, may redirect it
		// to a path chosen by the administrator.
		\WP_CLI::line( $json );
	}

	/** Imports and merges a portable JSON snapshot. */
	public function import( $args, $assoc_args ) {
		$path = $assoc_args['file'] ?? '';
		if ( ! $path || ! is_readable( $path ) ) { \WP_CLI::error( 'Provide a readable --file path.' ); }
		$data = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$result = is_array( $data ) ? Portability::merge( $data ) : new \WP_Error( 'json', 'Invalid JSON.' );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		\WP_CLI::success( 'OpenLingua data merged.' );
	}

	/** Prints diagnostic information. */
	public function diagnostics() {
		\WP_CLI::line( wp_json_encode( Diagnostics::report(), JSON_PRETTY_PRINT ) );
	}

	/** Lists recent translation jobs. */
	public function jobs( $args, $assoc_args ) {
		global $wpdb;
		$limit = min( 500, max( 1, absint( $assoc_args['limit'] ?? 50 ) ) );
		$status = sanitize_key( $assoc_args['status'] ?? '' );
		$table = \OpenLingua\Database::table( 'jobs' );
		if ( $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,source_id,target_id,target_language,provider,status,attempts,max_attempts,updated_at FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d', $table, $status, $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,source_id,target_id,target_language,provider,status,attempts,max_attempts,updated_at FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ), ARRAY_A );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'source_id', 'target_id', 'target_language', 'provider', 'status', 'attempts', 'max_attempts', 'updated_at' ) );
	}

	/** Runs one job immediately or recovers stalled workers. */
	public function run_job( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['recover'] ) ) {
			\WP_CLI::success( sprintf( 'Recovered %d stalled job(s).', Jobs::recover_stale() ) );
			return;
		}
		$job_id = absint( $args[0] ?? 0 );
		if ( ! $job_id ) { \WP_CLI::error( 'Provide a job ID or use --recover.' ); }
		$result = Jobs::run( $job_id );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		if ( ! $result ) { \WP_CLI::error( 'The job is not runnable or is already being processed.' ); }
		\WP_CLI::success( 'Translation job completed.' );
	}
}
