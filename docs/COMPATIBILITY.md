# Builder and plugin compatibility

SysOpenLang translates public WordPress posts, pages, custom post types, taxonomies, menus, media text, SEO fields, registered strings, and supported structured editor content. It does not assume a particular custom post type name.

## Native integrations

The plugin includes dedicated handling for:

- Gutenberg blocks, nested blocks, reusable blocks, tables, and common translatable attributes.
- Divi module content and Divi Theme Builder layouts.
- Elementor JSON documents with stable element and repeater identifiers.
- Supported ACF text, textarea, WYSIWYG, group, and repeater fields.
- WooCommerce product content, variations, attribute terms, and operational metadata synchronization.
- Core SEO output and metadata exposed by Yoast SEO, Rank Math, AIOSEO, and SEOPress.

## Automatic structured-builder discovery

For other builders, SysOpenLang inspects JSON or nested-array post metadata. A document is accepted only when it has recognizable builder structure and stable element identifiers such as `id`, `_id`, `uid`, or `uuid`. The stable identifier becomes part of every translation-segment key, so rearranging components does not move a translation into a neighboring field.

The generic extractor rejects fields that appear to contain URLs, IDs, media references, styles, colors, dimensions, alignment, animation, CSS, code, queries, cache data, backups, or other technical configuration. ACF-owned metadata is left to the dedicated ACF integration. PHP objects are never rewritten by generic discovery.

Administrators can enter a page or post ID under **SysOpenLang → Diagnostics → Visual builder field inspector**. The inspector is read-only and reports whether each custom field is recognized, excluded for safety, managed by ACF, opaque, or simply not a builder document.

## Dynamic output and shortcodes

Text rendered by registered shortcodes or JavaScript widgets can be discovered through the shortcode and interface-string workflows. Known translations are preloaded on translated pages to avoid a visible source-language flash. Content stored only on a remote service or generated inside an inaccessible compiled bundle may require cooperation from that plugin or a small adapter.

## Adding a dedicated adapter

Builders with a documented storage format can implement `SysOpenLang\Contracts\Content_Extractor` and register it on `sysopenlang_register_content_extractors`:

```php
add_action( 'sysopenlang_register_content_extractors', function () {
	SysOpenLang\Content_Extractors::register( new My_Builder_SysOpenLang_Extractor() );
} );
```

An extractor declares support for a post, returns stable human-readable segments, reads target values, and applies sanitized translations while preserving the builder document. Dedicated adapters take priority over generic structured discovery.

## Compatibility expectations

- Existing source and target content should be backed up before enabling a new third-party adapter on production.
- Automatic discovery intentionally prefers omitting an uncertain field over corrupting technical configuration.
- A plugin update that changes its private storage schema can require an adapter update.
- SysOpenLang filters allow developers to accept or exclude additional metadata keys and values without editing core plugin files.
