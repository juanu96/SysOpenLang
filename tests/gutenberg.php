<?php
$wordpress_root = getenv( 'SYSOPENLANG_WP_ROOT' ) ?: dirname( __DIR__, 4 );
define( 'ABSPATH', rtrim( $wordpress_root, '/\\' ) . '/' );

function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_kses_post( $value ) { return preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', (string) $value ); }
function has_blocks( $content ) { return false !== strpos( (string) $content, '<!-- wp:' ); }

require ABSPATH . 'wp-includes/class-wp-block-parser.php';

function parse_blocks( $content ) {
	return ( new WP_Block_Parser() )->parse( $content );
}

function serialize_block_attributes( $attributes ) {
	$encoded = json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return str_replace( array( '--', '<', '>', '&', '\\"' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ), $encoded );
}

function serialize_block( $block ) {
	$content = '';
	$inner_index = 0;
	foreach ( $block['innerContent'] as $chunk ) {
		$content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $inner_index++ ] );
	}
	if ( empty( $block['blockName'] ) ) { return $content; }
	$name = 0 === strpos( $block['blockName'], 'core/' ) ? substr( $block['blockName'], 5 ) : $block['blockName'];
	$attrs = empty( $block['attrs'] ) ? '' : ' ' . serialize_block_attributes( $block['attrs'] );
	return '' === $content ? '<!-- wp:' . $name . $attrs . ' /-->' : '<!-- wp:' . $name . $attrs . ' -->' . $content . '<!-- /wp:' . $name . ' -->';
}

function serialize_blocks( $blocks ) {
	return implode( '', array_map( 'serialize_block', $blocks ) );
}

require dirname( __DIR__ ) . '/src/class-gutenberg-content.php';

function gutenberg_assert( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	echo "PASS: {$message}\n";
}

$content = '<!-- wp:heading --><h2 class="wp-block-heading">Clean energy</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Power for everyone.</p><!-- /wp:paragraph -->'
	. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"text":"Explore services","url":"https://example.test/services"} --><div class="wp-block-button"><a class="wp-block-button__link">Explore services</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
	. '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Service</th><th>Price</th></tr></thead><tbody><tr><td>Solar plan</td><td><strong>Free estimate</strong></td></tr></tbody><caption>Available plans</caption></table></figure><!-- /wp:table -->'
	. '<!-- wp:acme/card {"title":"A better future","settings":{"description":"Made for every family.","color":"blue"},"className":"hero-card"} /-->';

$segments = \SysOpenLang\Gutenberg_Content::extract( $content );
$values = \SysOpenLang\Gutenberg_Content::values( $content );

gutenberg_assert( \SysOpenLang\Gutenberg_Content::is_gutenberg( $content ), 'detects Gutenberg block content' );
gutenberg_assert( in_array( '<h2 class="wp-block-heading">Clean energy</h2>', $values, true ), 'extracts heading HTML without block comments' );
gutenberg_assert( in_array( 'Explore services', $values, true ), 'extracts a custom text attribute' );
gutenberg_assert( in_array( 'Made for every family.', $values, true ), 'extracts nested third-party block attributes' );
gutenberg_assert( ! in_array( 'https://example.test/services', $values, true ), 'does not expose URLs for translation' );
gutenberg_assert( ! in_array( 'hero-card', $values, true ) && ! in_array( 'blue', $values, true ), 'ignores technical styling attributes' );
gutenberg_assert( in_array( 'Service', $values, true ) && in_array( 'Price', $values, true ), 'extracts table headers as separate fields' );
gutenberg_assert( in_array( 'Solar plan', $values, true ) && in_array( '<strong>Free estimate</strong>', $values, true ), 'extracts table cells without exposing table markup' );
gutenberg_assert( in_array( 'Available plans', $values, true ), 'extracts the table caption separately' );

$translations = array();
foreach ( $segments as $segment ) {
	if ( false !== strpos( $segment['value'], 'Clean energy' ) ) { $translations[ $segment['id'] ] = '<h2 class="wp-block-heading">Energía limpia</h2>'; }
	if ( 'Explore services' === $segment['value'] ) { $translations[ $segment['id'] ] = 'Explorar servicios'; }
	if ( 'Made for every family.' === $segment['value'] ) { $translations[ $segment['id'] ] = 'Creado para cada familia.'; }
	if ( 'Service' === $segment['value'] ) { $translations[ $segment['id'] ] = 'Servicio'; }
	if ( 'Solar plan' === $segment['value'] ) { $translations[ $segment['id'] ] = 'Plan solar'; }
	if ( '<strong>Free estimate</strong>' === $segment['value'] ) { $translations[ $segment['id'] ] = '<strong>Cotización gratuita</strong>'; }
	if ( 'Available plans' === $segment['value'] ) { $translations[ $segment['id'] ] = 'Planes disponibles'; }
}
$translated = \SysOpenLang\Gutenberg_Content::apply( $content, $translations );

gutenberg_assert( false !== strpos( $translated, 'Energía limpia' ), 'replaces native block HTML' );
gutenberg_assert( false !== strpos( $translated, 'Explorar servicios' ), 'replaces block attributes' );
gutenberg_assert( false !== strpos( $translated, 'Creado para cada familia.' ), 'replaces nested attributes' );
gutenberg_assert( false !== strpos( $translated, 'https://example.test/services' ), 'preserves URLs' );
gutenberg_assert( false !== strpos( $translated, '<th>Servicio</th><th>Price</th>' ), 'replaces individual table headers without changing adjacent cells' );
gutenberg_assert( false !== strpos( $translated, '<td>Plan solar</td><td><strong>Cotización gratuita</strong></td>' ), 'replaces plain and formatted table cells' );
gutenberg_assert( false !== strpos( $translated, '<caption>Planes disponibles</caption>' ), 'replaces the table caption' );
gutenberg_assert( substr_count( $translated, '<tr>' ) === substr_count( $content, '<tr>' ) && substr_count( $translated, '<td>' ) === substr_count( $content, '<td>' ), 'preserves table rows and cells' );
gutenberg_assert( count( parse_blocks( $translated ) ) === count( parse_blocks( $content ) ), 'preserves the top-level block structure' );

$old_blocks = '<!-- wp:paragraph --><p>First message</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second message</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Third message</p><!-- /wp:paragraph -->';
$old_block_translation = '<!-- wp:paragraph --><p>Primer mensaje</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Segundo mensaje</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Tercer mensaje</p><!-- /wp:paragraph -->';
$reordered_blocks = '<!-- wp:paragraph --><p>Third message</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>First message</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>New message</p><!-- /wp:paragraph -->';
$aligned_blocks = \SysOpenLang\Gutenberg_Content::aligned_values( $reordered_blocks, $old_block_translation, \SysOpenLang\Gutenberg_Content::source_snapshot( $old_blocks ) );
$reordered_segments = \SysOpenLang\Gutenberg_Content::extract( $reordered_blocks );
gutenberg_assert( '<p>Tercer mensaje</p>' === $aligned_blocks[ $reordered_segments[0]['id'] ], 'keeps a translated block attached after reordering' );
gutenberg_assert( '<p>Primer mensaje</p>' === $aligned_blocks[ $reordered_segments[1]['id'] ], 'reconciles another translated block independently of position' );
gutenberg_assert( '' === $aligned_blocks[ $reordered_segments[2]['id'] ], 'leaves newly inserted blocks empty for translation' );

echo "All SysOpenLang Gutenberg extractor tests passed.\n";
