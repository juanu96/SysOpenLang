<?php
namespace SysOpenLang;

defined( 'ABSPATH' ) || exit;

/**
 * Translation management for block-theme content that WordPress keeps in
 * internal post types instead of the regular Posts and Pages screens.
 */
final class Global_Content {
	const PAGE = 'openlingua-global-content';

	private static function types() {
		return array(
			'wp_template'      => __( 'Templates', 'sysopenlang' ),
			'wp_template_part' => __( 'Template parts', 'sysopenlang' ),
			'wp_navigation'    => __( 'Navigation', 'sysopenlang' ),
			'wp_block'         => __( 'Patterns', 'sysopenlang' ),
		);
	}

	public static function hooks() {
		// Register after SysOpenLang's parent menu so WordPress grants access to the submenu route.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 17 );
		add_filter( 'get_block_template', array( __CLASS__, 'translate_template' ), 20, 3 );
		add_filter( 'get_block_templates', array( __CLASS__, 'translate_templates' ), 20, 3 );
	}

	public static function menu() {
		add_submenu_page( 'openlingua', __( 'Global content translations', 'sysopenlang' ), __( 'Global content', 'sysopenlang' ), 'edit_theme_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'edit_theme_options' ) ) { return; }
		$type = isset( $_GET['content_type'] ) ? sanitize_key( wp_unslash( $_GET['content_type'] ) ) : 'wp_template'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) { $type = 'wp_template'; }
		$language = Admin::content_language();
		if ( 'all' === $language || ! Languages::is_valid( $language ) ) { $language = Languages::default_code(); }
		$posts = get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$posts = array_values( array_filter( $posts, static function ( $post ) use ( $language ) {
			$row = Translations::row( 'post', $post->ID );
			return $row ? $language === $row->language : Languages::default_code() === $language;
		} ) );
		$return_to = add_query_arg( array( 'page' => self::PAGE, 'content_type' => $type, 'lang' => $language ), admin_url( 'admin.php' ) );

		echo '<div class="wrap openlingua-global-content"><h1>' . esc_html__( 'Global content translations', 'sysopenlang' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Translate block templates, headers, footers, navigation blocks and reusable patterns from one place.', 'sysopenlang' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><label for="openlingua-global-type" class="screen-reader-text">' . esc_html__( 'Content type', 'sysopenlang' ) . '</label><select id="openlingua-global-type" name="content_type">';
		foreach ( $types as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $type, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><label for="openlingua-global-language" class="screen-reader-text">' . esc_html__( 'Source language', 'sysopenlang' ) . '</label><select id="openlingua-global-language" name="lang">';
		foreach ( Languages::all() as $code => $item ) { echo '<option value="' . esc_attr( $code ) . '" ' . selected( $language, $code, false ) . '>' . esc_html( ( $item['flag'] ?? '🌐' ) . ' ' . $item['name'] ) . '</option>'; }
		echo '</select> '; submit_button( __( 'Filter', 'sysopenlang' ), 'secondary', '', false ); echo '</form>';
		if ( ! $posts ) { echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No global content exists for this type and language.', 'sysopenlang' ) . '</p></div></div>'; return; }
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Title', 'sysopenlang' ) . '</th><th>' . esc_html__( 'Type', 'sysopenlang' ) . '</th>';
		foreach ( Languages::all() as $code => $item ) { if ( $code !== $language ) { echo '<th><span title="' . esc_attr( $item['name'] ) . '">' . esc_html( $item['flag'] ?? strtoupper( $code ) ) . '</span></th>'; } }
		echo '</tr></thead><tbody>';
		foreach ( $posts as $post ) {
			echo '<tr><td><strong>' . esc_html( $post->post_title ?: $post->post_name ) . '</strong></td><td>' . esc_html( $types[ $type ] ) . '</td>';
			foreach ( Languages::all() as $code => $item ) { if ( $code !== $language ) { echo '<td>'; self::render_action( $post->ID, $code, $return_to ); echo '</td>'; } }
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_action( $post_id, $language, $return_to ) {
		$group = Translations::group( 'post', $post_id );
		$name = Languages::all()[ $language ]['name'] ?? strtoupper( $language );
		if ( ! empty( $group[ $language ] ) && 'trash' !== get_post_status( $group[ $language ] ) ) {
			$url = Translation_Editor::url( $post_id, $group[ $language ], $return_to );
			/* translators: %s: language name. */
			$label = sprintf( __( 'Edit %s translation', 'sysopenlang' ), $name );
			$icon = 'dashicons-edit';
		} else {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'openlingua_duplicate', 'post_id' => $post_id, 'language' => $language, 'redirect_to' => $return_to ), admin_url( 'admin-post.php' ) ), 'openlingua_duplicate_' . $post_id );
			/* translators: %s: language name. */
			$label = sprintf( __( 'Add %s translation', 'sysopenlang' ), $name );
			$icon = 'dashicons-plus-alt2';
		}
		echo '<a href="' . esc_url( $url ) . '" class="openlingua-translation-action" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span></a>';
	}

	public static function translate_template( $template, $id, $template_type ) {
		if ( is_admin() || ! in_array( $template_type, array( 'wp_template', 'wp_template_part' ), true ) ) { return $template; }
		return self::translated_template_object( $template );
	}

	public static function translate_templates( $templates, $query, $template_type ) {
		if ( is_admin() || ! in_array( $template_type, array( 'wp_template', 'wp_template_part' ), true ) ) { return $templates; }
		foreach ( $templates as $index => $template ) { $templates[ $index ] = self::translated_template_object( $template ); }
		return $templates;
	}

	private static function translated_template_object( $template ) {
		if ( ! is_object( $template ) || empty( $template->wp_id ) || Languages::current() === Languages::default_code() ) { return $template; }
		$translated_id = Translations::translated_id_with_fallback( 'post', absint( $template->wp_id ), Languages::current() );
		if ( ! $translated_id || 'publish' !== get_post_status( $translated_id ) || ! function_exists( '_build_block_template_result_from_post' ) ) { return $template; }
		$post = get_post( $translated_id );
		return $post ? _build_block_template_result_from_post( $post ) : $template;
	}
}
