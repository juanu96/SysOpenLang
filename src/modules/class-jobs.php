<?php
namespace SysOpenLang\Modules;

use SysOpenLang\Contracts\Module;
use SysOpenLang\Database;
use SysOpenLang\Divi_Content;
use SysOpenLang\Gutenberg_Content;
use SysOpenLang\Content_Extractors;
use SysOpenLang\ACF_Content;
use SysOpenLang\SEO;
use SysOpenLang\Translation_Editor;
use SysOpenLang\Translation_Memory;

defined( 'ABSPATH' ) || exit;

final class Jobs implements Module {
	const RECOVERY_HOOK = 'openlingua_recover_translation_jobs';
	const STALE_AFTER = 15 * MINUTE_IN_SECONDS;

	public static function hooks() {
		add_action( 'openlingua_run_translation_job', array( __CLASS__, 'run' ) );
		add_action( 'admin_post_openlingua_run_job', array( __CLASS__, 'run_from_admin' ) );
		add_action( 'admin_post_openlingua_enqueue_translation', array( __CLASS__, 'enqueue_from_admin' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'openlingua_translation_job_completed', array( __CLASS__, 'remember_completion' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'completion_notice' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule_recovery' ) );
		add_action( self::RECOVERY_HOOK, array( __CLASS__, 'recover_stale' ) );
	}

	public static function enqueue( $source_id, $target_id, $target_language, $provider_id ) {
		global $wpdb;
		$source = get_post( $source_id );
		$target = get_post( $target_id );
		$provider = Providers::get( $provider_id );
		if ( ! $source || ! $target || ! $provider || ! $provider->is_configured() ) {
			return new \WP_Error( 'openlingua_invalid_job', __( 'The translation job is not valid or the provider is not configured.', 'sysopenlang' ) );
		}
		$behavior = Site_Settings::get();
		$existing = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE source_id = %d AND target_id = %d AND provider = %s AND status IN ('pending','retrying','processing') ORDER BY id DESC LIMIT 1", Database::table( 'jobs' ), absint( $source_id ), absint( $target_id ), sanitize_key( $provider_id ) ) ) );
		if ( $existing ) { return $existing; }
		$monthly_limit = absint( $behavior['monthly_job_limit'] );
		if ( $monthly_limit ) {
			$month_start = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );
			$count = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', Database::table( 'jobs' ), $month_start ) ) );
			if ( $count >= $monthly_limit ) { return new \WP_Error( 'openlingua_monthly_limit', __( 'The configured monthly automatic-translation limit has been reached.', 'sysopenlang' ) ); }
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( Database::table( 'jobs' ), array(
			'source_id' => absint( $source_id ), 'target_id' => absint( $target_id ),
			'target_language' => sanitize_key( $target_language ), 'provider' => sanitize_key( $provider_id ),
			'status' => 'pending', 'attempts' => 0, 'max_attempts' => max( 1, absint( $behavior['max_attempts'] ) ), 'available_at' => $now,
			'payload' => wp_json_encode( array( 'requested_by' => get_current_user_id() ) ), 'error' => '', 'created_at' => $now, 'updated_at' => $now,
		) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'openlingua_job_db', $wpdb->last_error ); }
		$job_id = absint( $wpdb->insert_id );
		wp_schedule_single_event( time() + 5, 'openlingua_run_translation_job', array( $job_id ) );
		return $job_id;
	}

	public static function enqueue_url( $source_id, $target_id, $provider_id, $return_to = '' ) {
		$url = add_query_arg( array( 'action' => 'openlingua_enqueue_translation', 'source_id' => absint( $source_id ), 'target_id' => absint( $target_id ), 'provider' => sanitize_key( $provider_id ), 'return_to' => $return_to ), admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, 'openlingua_enqueue_translation_' . absint( $target_id ) );
	}

	public static function enqueue_from_admin() {
		$source_id = absint( $_GET['source_id'] ?? 0 );
		$target_id = absint( $_GET['target_id'] ?? 0 );
		$provider_id = sanitize_key( wp_unslash( $_GET['provider'] ?? '' ) );
		check_admin_referer( 'openlingua_enqueue_translation_' . $target_id );
		if ( ! current_user_can( 'openlingua_translate' ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $target_id ) ) { wp_die( esc_html__( 'You cannot translate this content.', 'sysopenlang' ) ); }
		$source = \SysOpenLang\Translations::row( 'post', $source_id );
		$target = \SysOpenLang\Translations::row( 'post', $target_id );
		if ( ! $source || ! $target || $source->group_uuid !== $target->group_uuid ) { wp_die( esc_html__( 'These posts are not linked translations.', 'sysopenlang' ) ); }
		$return_to = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['return_to'] ?? '' ) ), '' );
		$editor_url = Translation_Editor::url( $source_id, $target_id, $return_to );
		$job_id = self::enqueue( $source_id, $target_id, $target->language, $provider_id );
		$status = is_wp_error( $job_id ) ? 'error' : 'queued';
		wp_safe_redirect( add_query_arg( array( 'automatic_translation' => $status, 'provider' => $provider_id ), $editor_url ) );
		exit;
	}

	public static function run( $job_id ) {
		global $wpdb;
		$table = Database::table( 'jobs' );
		$job_id = absint( $job_id );
		$now = current_time( 'mysql' );
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET status = 'processing', attempts = attempts + 1, started_at = %s, updated_at = %s WHERE id = %d AND status IN ('pending','retrying','failed') AND (available_at IS NULL OR available_at <= %s)",
			$table, $now, $now, $job_id, $now
		) );
		if ( 1 !== $claimed ) { return false; }
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $job_id ) );
		if ( ! $job ) { return false; }
		$provider = Providers::get( $job->provider );
		$source   = get_post( $job->source_id );
		$target   = get_post( $job->target_id );
		if ( ! $provider || ! $provider->is_configured() || ! $source || ! $target ) { return self::fail( $job_id, __( 'Provider or source content is unavailable.', 'sysopenlang' ) ); }
		$is_divi = Divi_Content::is_divi( $source->post_content );
		$is_gutenberg = ! $is_divi && Gutenberg_Content::is_gutenberg( $source->post_content );
		$content_extractor = ! $is_divi && ! $is_gutenberg ? Content_Extractors::for_post( $source ) : null;
		$divi_segments = $is_divi ? Divi_Content::extract( $source->post_content ) : array();
		$gutenberg_segments = $is_gutenberg ? Gutenberg_Content::extract( $source->post_content ) : array();
		$extractor_segments = $content_extractor ? $content_extractor->extract( $source ) : array();
		$acf_segments = ACF_Content::extract( $source->ID );
		$seo_groups = SEO::translation_fields( $source->ID, $target->ID );
		$commerce_fields = Commerce::translation_fields( $source->ID, $target->ID );
		$segments = array( 'title' => $source->post_title, 'excerpt' => $source->post_excerpt );
		if ( $is_divi ) {
			foreach ( $divi_segments as $segment ) { $segments[ $segment['id'] ] = $segment['value']; }
		} elseif ( $is_gutenberg ) {
			foreach ( $gutenberg_segments as $segment ) { $segments[ $segment['id'] ] = $segment['value']; }
		} elseif ( $content_extractor ) {
			foreach ( $extractor_segments as $segment ) { $segments[ $segment['id'] ] = $segment['value']; }
		} else {
			$segments['content'] = $source->post_content;
		}
		foreach ( $acf_segments as $segment ) { $segments[ 'acf__' . $segment['id'] ] = $segment['value']; }
		foreach ( $commerce_fields as $field ) { $segments[ 'commerce__' . $field['id'] ] = $field['source']; }
		foreach ( $seo_groups as $group ) {
			foreach ( $group['fields'] as $field ) { if ( '' !== trim( $field['source'] ) ) { $segments[ 'seo__' . $field['id'] ] = $field['source']; } }
		}
		$result = $provider->translate( $segments, self::source_language( $job->source_id ), $job->target_language, array( 'post_id' => absint( $job->source_id ) ) );
		if ( is_wp_error( $result ) ) { return self::fail( $job_id, $result->get_error_message() ); }
		if ( ! is_array( $result ) || ! isset( $result['title'] ) || ( ! $is_divi && ! $is_gutenberg && ! $content_extractor && ! isset( $result['content'] ) ) ) { return self::fail( $job_id, __( 'Provider returned an invalid response.', 'sysopenlang' ) ); }
		if ( $is_divi ) {
			$translated_divi = array();
			foreach ( $divi_segments as $segment ) {
				if ( ! array_key_exists( $segment['id'], $result ) ) { continue; }
				$translated_divi[ $segment['id'] ] = 'attribute' === $segment['kind'] ? sanitize_text_field( $result[ $segment['id'] ] ) : wp_kses_post( $result[ $segment['id'] ] );
			}
			$content = Divi_Content::apply_for_post( $source->ID, $source->post_content, $translated_divi );
			$content = Divi_Content::restore_embedded_shortcodes( $source->post_content, $content );
		} elseif ( $is_gutenberg ) {
			$translated_blocks = array();
			foreach ( $gutenberg_segments as $segment ) {
				if ( ! array_key_exists( $segment['id'], $result ) ) { continue; }
				$translated_blocks[ $segment['id'] ] = 'html' === $segment['format'] ? wp_kses_post( $result[ $segment['id'] ] ) : sanitize_textarea_field( $result[ $segment['id'] ] );
			}
			$base_content = Gutenberg_Content::is_gutenberg( $target->post_content ) ? $target->post_content : $source->post_content;
			$content = Gutenberg_Content::apply( $base_content, $translated_blocks, $job->target_language );
		} elseif ( $content_extractor ) {
			$content = $target->post_content;
		} else {
			$content = wp_kses_post( $result['content'] );
		}
		$translated_title = sanitize_text_field( $result['title'] );
		$update = array( 'ID' => absint( $job->target_id ), 'post_title' => $translated_title, 'post_excerpt' => wp_kses_post( $result['excerpt'] ?? '' ), 'post_content' => $content );
		$behavior = Site_Settings::get();
		$desired_slug = 'source' === $behavior['slug_mode'] ? $source->post_name : sanitize_title( $translated_title );
		if ( 'manual' !== $behavior['slug_mode'] && $desired_slug && ( ! $target->post_name || 'draft' === $target->post_status || sanitize_title( $source->post_title ) === $target->post_name || preg_match( '/^' . preg_quote( $desired_slug, '/' ) . '-\d+$/', $target->post_name ) ) ) {
			$update['post_name'] = wp_unique_post_slug( $desired_slug, $target->ID, $target->post_status, $target->post_type, $target->post_parent );
		}
		$updated = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $updated ) ) { return self::fail( $job_id, $updated->get_error_message() ); }
		if ( $content_extractor ) {
			$translated_extractor = array();
			foreach ( $extractor_segments as $segment ) {
				if ( array_key_exists( $segment['id'], $result ) ) { $translated_extractor[ $segment['id'] ] = $result[ $segment['id'] ]; }
			}
			$content_extractor->apply( $source, $target, $translated_extractor, $job->target_language );
		}
		if ( $is_divi ) { update_post_meta( $target->ID, Divi_Content::SOURCE_SNAPSHOT_META, Divi_Content::source_snapshot( $source->post_content ) ); }
		if ( $is_gutenberg ) { update_post_meta( $target->ID, Gutenberg_Content::SOURCE_SNAPSHOT_META, Gutenberg_Content::source_snapshot( $source->post_content ) ); }
		$acf_translation = array();
		foreach ( $acf_segments as $segment ) {
			$key = 'acf__' . $segment['id'];
			if ( array_key_exists( $key, $result ) ) { $acf_translation[ $segment['id'] ] = $result[ $key ]; }
		}
		ACF_Content::save( $source->ID, $target->ID, $acf_translation, true );
		update_post_meta( $target->ID, ACF_Content::SOURCE_SNAPSHOT_META, ACF_Content::source_snapshot( $source->ID ) );
		$seo_translation = array();
		foreach ( $seo_groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				$key = 'seo__' . $field['id'];
				if ( array_key_exists( $key, $result ) ) { $seo_translation[ $field['id'] ] = $result[ $key ]; }
			}
		}
		SEO::save_translation_fields( $source->ID, $target->ID, $seo_translation );
		$commerce_translation = array();
		foreach ( $commerce_fields as $field ) {
			$key = 'commerce__' . $field['id'];
			if ( array_key_exists( $key, $result ) ) { $commerce_translation[ $field['id'] ] = $result[ $key ]; }
		}
		Commerce::save_translation_fields( $source->ID, $target->ID, $commerce_translation );
		Translation_Memory::learn_post( $source->ID, $target->ID, 'automatic', false );
		$result_mode = $behavior['automatic_result'];
		update_post_meta( $job->target_id, Workflow::STATUS_META, 'publish' === $result_mode ? 'complete' : 'in-progress' );
		$payload = json_decode( (string) $job->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		$requester = absint( $payload['requested_by'] ?? 0 );
		$post_type = get_post_type_object( $target->post_type );
		$publish_cap = $post_type && ! empty( $post_type->cap->publish_posts ) ? $post_type->cap->publish_posts : 'publish_posts';
		if ( 'publish' === $result_mode && 'publish' === $source->post_status && $requester && user_can( $requester, $publish_cap ) ) { wp_update_post( array( 'ID' => $target->ID, 'post_status' => 'publish' ) ); }
		$payload['segments'] = array_keys( $segments );
		$wpdb->update( $table, array( 'status' => 'complete', 'payload' => wp_json_encode( $payload ), 'error' => '', 'started_at' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $job_id ) );
		do_action( 'openlingua_translation_job_completed', $job_id, $job );
		return true;
	}

	private static function source_language( $post_id ) {
		$row = \SysOpenLang\Translations::row( 'post', $post_id );
		return $row ? $row->language : \SysOpenLang\Languages::default_code();
	}

	private static function fail( $job_id, $message ) {
		global $wpdb;
		$table = Database::table( 'jobs' );
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT attempts,max_attempts FROM %i WHERE id = %d', $table, absint( $job_id ) ) );
		$attempts = absint( $job->attempts ?? 1 );
		$max_attempts = max( 1, absint( $job->max_attempts ?? 3 ) );
		$status = $attempts < $max_attempts ? 'retrying' : 'failed';
		$delay = min( HOUR_IN_SECONDS, 30 * ( 2 ** max( 0, $attempts - 1 ) ) );
		$available = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay );
		$wpdb->update( $table, array( 'status' => $status, 'error' => sanitize_textarea_field( $message ), 'available_at' => $available, 'started_at' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => absint( $job_id ) ) );
		if ( 'retrying' === $status ) { wp_schedule_single_event( time() + $delay, 'openlingua_run_translation_job', array( absint( $job_id ) ) ); }
		return new \WP_Error( 'openlingua_job_failed', $message );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['openlingua_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every five minutes', 'sysopenlang' ) );
		return $schedules;
	}

	public static function schedule_recovery() {
		if ( ! wp_next_scheduled( self::RECOVERY_HOOK ) ) { wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'openlingua_five_minutes', self::RECOVERY_HOOK ); }
	}

	public static function recover_stale() {
		global $wpdb;
		$table = Database::table( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STALE_AFTER );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'processing' AND started_at IS NOT NULL AND started_at < %s", $table, $cutoff ) );
		foreach ( (array) $ids as $job_id ) {
			$wpdb->update( $table, array( 'status' => 'retrying', 'available_at' => current_time( 'mysql' ), 'started_at' => null, 'error' => __( 'The worker stopped before finishing. SysOpenLang queued the job again.', 'sysopenlang' ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => absint( $job_id ), 'status' => 'processing' ) );
			wp_schedule_single_event( time() + 5, 'openlingua_run_translation_job', array( absint( $job_id ) ) );
		}
		return count( $ids );
	}

	public static function remember_completion( $job_id, $job ) {
		$payload = json_decode( (string) $job->payload, true );
		$user_id = absint( $payload['requested_by'] ?? 0 );
		if ( ! $user_id ) { return; }
		$notices = (array) get_user_meta( $user_id, '_openlingua_completed_jobs', true );
		$notices[] = array( 'job_id' => absint( $job_id ), 'source_id' => absint( $job->source_id ), 'target_id' => absint( $job->target_id ), 'time' => time() );
		update_user_meta( $user_id, '_openlingua_completed_jobs', array_slice( $notices, -10 ) );
	}

	public static function completion_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return; }
		$notices = (array) get_user_meta( $user_id, '_openlingua_completed_jobs', true );
		if ( ! $notices ) { return; }
		delete_user_meta( $user_id, '_openlingua_completed_jobs' );
		foreach ( $notices as $notice ) {
			$source_id = absint( $notice['source_id'] ?? 0 );
			$target_id = absint( $notice['target_id'] ?? 0 );
			if ( ! $source_id || ! $target_id || ! get_post( $source_id ) || ! get_post( $target_id ) ) { continue; }
			$url = Translation_Editor::url( $source_id, $target_id );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'SysOpenLang finished the automatic translation. It is ready for review.', 'sysopenlang' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Review translation', 'sysopenlang' ) . '</a></p></div>';
		}
	}

	public static function run_from_admin() {
		$job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
		check_admin_referer( 'openlingua_run_job_' . $job_id );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'sysopenlang' ) ); }
		global $wpdb;
		$wpdb->update( Database::table( 'jobs' ), array( 'status' => 'pending', 'attempts' => 0, 'available_at' => current_time( 'mysql' ), 'started_at' => null ), array( 'id' => $job_id, 'status' => 'failed' ) );
		self::run( $job_id );
		wp_safe_redirect( add_query_arg( 'page', 'openlingua-jobs', admin_url( 'admin.php' ) ) ); exit;
	}

	public static function admin_menu() {
		add_submenu_page( 'openlingua', __( 'Translation jobs', 'sysopenlang' ), __( 'Jobs', 'sysopenlang' ), 'manage_options', 'openlingua-jobs', array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'openlingua_page_openlingua-jobs', 'sysopenlang_page_openlingua-jobs' ), true ) ) { return; }
		wp_enqueue_style( 'openlingua-jobs', plugins_url( 'assets/admin-jobs.css', SYSOPENLANG_FILE ), array(), SYSOPENLANG_VERSION );
	}

	private static function status_label( $status ) {
		$labels = array(
			'pending' => __( 'Queued', 'sysopenlang' ),
			'retrying' => __( 'Waiting to retry', 'sysopenlang' ),
			'processing' => __( 'Translating', 'sysopenlang' ),
			'complete' => __( 'Ready for review', 'sysopenlang' ),
			'failed' => __( 'Failed', 'sysopenlang' ),
		);
		return $labels[ $status ] ?? ucfirst( $status );
	}

	public static function page() {
		global $wpdb;
		$jobs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 100', Database::table( 'jobs' ) ) );
		$allowed_action_html = array(
			'a'    => array( 'class' => true, 'href' => true ),
			'span' => array( 'class' => true ),
		);
		echo '<div class="wrap openlingua-jobs"><h1>' . esc_html__( 'Translation jobs', 'sysopenlang' ) . '</h1>';
		echo '<p>' . esc_html__( 'Automatic jobs start on their own. This screen shows whether each translation is waiting, running, ready to review, or needs attention.', 'sysopenlang' ) . '</p>';
		echo '<div class="openlingua-jobs__legend"><span class="openlingua-job-status openlingua-job-status--pending">' . esc_html__( 'Queued', 'sysopenlang' ) . '</span><span class="openlingua-job-status openlingua-job-status--retrying">' . esc_html__( 'Waiting to retry', 'sysopenlang' ) . '</span><span class="openlingua-job-status openlingua-job-status--processing">' . esc_html__( 'Translating', 'sysopenlang' ) . '</span><span class="openlingua-job-status openlingua-job-status--complete">' . esc_html__( 'Ready for review', 'sysopenlang' ) . '</span><span class="openlingua-job-status openlingua-job-status--failed">' . esc_html__( 'Failed', 'sysopenlang' ) . '</span></div>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Source', 'sysopenlang' ) . '</th><th>' . esc_html__( 'Target', 'sysopenlang' ) . '</th><th>' . esc_html__( 'Provider', 'sysopenlang' ) . '</th><th>' . esc_html__( 'Status', 'sysopenlang' ) . '</th><th>' . esc_html__( 'Action', 'sysopenlang' ) . '</th></tr></thead><tbody>';
		foreach ( $jobs as $job ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'openlingua_run_job', 'job_id' => $job->id ), admin_url( 'admin-post.php' ) ), 'openlingua_run_job_' . $job->id );
			$source = get_post( $job->source_id );
			$target = get_post( $job->target_id );
			$source_label = $source ? get_the_title( $source ) . ' (#' . absint( $job->source_id ) . ')' : '#' . absint( $job->source_id );
			$target_label = $target ? get_the_title( $target ) . ' (#' . absint( $job->target_id ) . ')' : '#' . absint( $job->target_id );
			$action = '&mdash;';
			if ( 'failed' === $job->status ) { $action = '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Retry', 'sysopenlang' ) . '</a>'; }
			elseif ( 'complete' === $job->status && $source && $target ) { $action = '<a class="button button-primary" href="' . esc_url( Translation_Editor::url( $source->ID, $target->ID ) ) . '">' . esc_html__( 'Review translation', 'sysopenlang' ) . '</a>'; }
			elseif ( in_array( $job->status, array( 'pending', 'retrying' ), true ) ) { $action = '<span class="description">' . esc_html__( 'Starts automatically', 'sysopenlang' ) . '</span>'; }
			elseif ( 'processing' === $job->status ) { $action = '<span class="description">' . esc_html__( 'Please wait', 'sysopenlang' ) . '</span>'; }
			$attempts = absint( $job->attempts ?? 0 ) . '/' . max( 1, absint( $job->max_attempts ?? 3 ) );
			/* translators: %s: current attempt and maximum attempts, for example 1/3. */
			echo '<tr><td>' . absint( $job->id ) . '</td><td>' . esc_html( $source_label ) . '</td><td>' . esc_html( $target_label ) . '</td><td>' . esc_html( $job->provider ) . '</td><td><span class="openlingua-job-status openlingua-job-status--' . esc_attr( $job->status ) . '">' . esc_html( self::status_label( $job->status ) ) . '</span><div class="description">' . esc_html( sprintf( __( 'Attempts: %s', 'sysopenlang' ), $attempts ) ) . '</div>' . ( $job->error ? '<div class="openlingua-job-error">' . esc_html( $job->error ) . '</div>' : '' ) . '</td><td>' . wp_kses( $action, $allowed_action_html ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
