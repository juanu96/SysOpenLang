# SysOpenLang

SysOpenLang is a free, GPL-licensed multilingual foundation for WordPress. It provides language-aware content, URLs, menus, taxonomies, custom fields, SEO metadata, interface strings, shortcodes, Divi layouts, and optional automatic translation without locking a site into a proprietary translation service.

Current plugin version: **1.20.26**. Requires WordPress 6.4 or newer and PHP 7.4 or newer.

The guided first-install wizard configures languages, URL format, selector appearance, media behavior, and editorial workflow in five interactive steps. New sites open it automatically on their first SysOpenLang visit, while upgrades remain uninterrupted. The central settings screen then controls how translations are created, reviewed, published, indexed, rediscovered, retained, and removed. Translation permissions and notification recipients are configured independently.

SysOpenLang includes a local translation memory. Exact translations that you save manually or generate with an automatic provider can fill matching empty fields, including fields that still contain an untouched copy of the original text, on the same page and on other translated content. Existing translations are never overwritten, historical translations are imported automatically, and plain text is kept separate from HTML-compatible content.

Slider Revolution modules embedded in Divi are protected as technical configuration. Their aliases, IDs, encoded shortcodes, and module settings are never exposed as page text. Visible slider layers are discovered from the rendered shortcode output and can be translated under SysOpenLang → Shortcodes after an administrator visits the source-language page.

When an authorized editor visits a translated page on the front end, the WordPress toolbar includes an **Edit translation** action that opens the SysOpenLang editor for that exact source and target pair.

Known translations for shortcode and JavaScript-rendered content are preloaded with the page and applied in the same browser rendering cycle in which dynamic nodes appear. The plugin output itself always remains visible; a previously unseen label no longer hides an entire widget while REST discovery runs in the background. A short-lived browser cache accelerates subsequent visits.

Divi translations track a source fingerprint for every translatable module field. When the source layout deletes, inserts, or reorders modules, SysOpenLang rejects stale positional matches, realigns unchanged values, restores known text from translation memory, and saves the target on top of the current source structure. New sections, images, and technical settings are therefore synchronized without shifting existing translations into adjacent fields.

SysOpenLang is independently developed and distributed under the GPL-2.0-or-later license.

## Main features

### Languages and URLs

- Enable languages from a broad built-in catalog or register a custom language.
- Choose the default language and WordPress locale.
- Support left-to-right and right-to-left languages.
- Use language directories (`/es/page/`), query parameters (`?lang=es`), or separate language domains.
- Generate language-aware canonical URLs and alternate `hreflang` links.
- Optionally redirect visitors according to their browser language.
- Keep hidden languages editable by administrators while excluding them from public discovery.

### Pages, posts, and custom post types

- Works automatically with public WordPress post types instead of relying on hardcoded CPT names.
- Shows add, edit, view, delete, restore, and language-status actions in WordPress list tables.
- Filters the admin list to the language currently selected in the SysOpenLang admin bar.
- Maintains translation groups with one element per language.
- Allows translated content to reuse the same slug under different language paths, such as `/en/example-page/` and `/es/example-page/`.
- Provides a side-by-side translation editor with visual and HTML editing modes.

### Media library

- Reuses a single WordPress attachment across translated pages by default instead of creating language copies.
- Hides legacy translated attachment copies in unified mode without deleting files or database records.
- Optionally assigns new uploads to the current content language and filters the Media Library accordingly.
- Applies the same language filter to WordPress media modals used by Gutenberg, Divi, and third-party visual builders.
- Keeps the **All languages** administrative view available and makes switching modes fully reversible.

### WooCommerce

- Translates product titles, descriptions, excerpts, taxonomies, SEO metadata, and custom fields through the standard SysOpenLang workflow.
- Creates or adopts matching target variations and stores a stable relationship to each source variation.
- Maps global attribute terms and variation slugs to translated taxonomy terms while preserving local attribute values.
- Synchronizes prices, inventory, downloads, dimensions, images, status, ordering, and translated related-product IDs.
- Exposes variation descriptions in the manual editor, translation memory, and automatic translation jobs.
- Never copies a variation SKU or global unique identifier into another variation.

### Divi support

- Extracts translatable text from Divi modules while preserving shortcode structure and builder settings.
- Discovers human-readable attributes and body text from third-party Divi modules without plugin-specific field lists.
- Recognizes registered Divi module callbacks, standard Divi metadata, and common third-party naming conventions.
- Excludes technical settings, URLs, IDs, JSON, responsive values, styles, and dynamic-content payloads automatically.
- Recognizes URL-encoded configuration objects, UUID-based global design metadata, and other machine payloads that should never be translated.
- Avoids duplicated text from nested carousel and other container modules.
- Keeps visual-mode fields readable instead of exposing raw HTML by default.
- Supports translated Divi Theme Builder headers, bodies, and footers.
- Preserves dynamic post-content modules and layout metadata.
- Provides a dedicated **SysOpenLang → Divi Theme Builder** overview for creating and editing layout translations.

### Gutenberg support

- Extracts visible content from native, nested, and third-party blocks without exposing Gutenberg comments or block JSON.
- Detects common and nested translatable block attributes while excluding URLs, IDs, CSS classes, colors, layout settings, and other technical values.
- Preserves block structure when translations are saved manually or generated by an automatic translation provider.
- Maps synchronized-pattern references to their translated `wp_block` post when that translation exists.
- Discovers visible labels and accessibility attributes rendered by dynamic PHP blocks under the Strings translation workflow.
- Keeps mixed classic and block content translatable through a generic freeform block segment.
- Maps Gutenberg table headers, cells, and captions individually while preserving rows, sections, formatting, and table attributes.
- Initializes the WordPress locale from the requested language before calendars and other locale-dependent core output are rendered.

### Elementor and builder integrations

- Extracts human-readable Elementor widget and repeater text from `_elementor_data` while preserving design controls, URLs, media references, IDs, and layout settings.
- Uses Elementor element and repeater IDs so translations remain attached when widgets are reordered.
- Supports manual translation, configured automatic providers, and local translation memory through the same editor.
- Exposes `SysOpenLang\Contracts\Content_Extractor`, `SysOpenLang\Content_Extractors::register()`, and the `sysopenlang_register_content_extractors` action for independent builder adapters.
- Detects nested JSON and array-based builder documents automatically when they expose stable element identities.
- Excludes ACF-owned values, URLs, media references, design controls, code, cache payloads, and opaque objects.
- Includes a read-only field inspector under diagnostics so site owners can see why each metadata field is accepted or excluded.

See [Builder and plugin compatibility](docs/COMPATIBILITY.md) for the supported contract, fallback behavior, and adapter guidance.

### Interface strings

- Applies saved SysOpenLang translations to gettext strings emitted by themes and plugins.
- Preserves WordPress native locale translations whenever no SysOpenLang override exists.
- Separates contextual strings and supports basic singular/plural variants.
- Discovery remains explicitly configurable and does not write new database rows while disabled.

### Global block content

- Provides a dedicated SysOpenLang screen for block templates, template parts, navigation entities, and reusable patterns.
- Uses the existing side-by-side translation editor and language actions for this global content.
- Resolves published block-template and template-part translations on the frontend for the requested language.
- Keeps the source template as a safe fallback until its translation is published.

### Internal relationships

- Maps reusable-block and navigation references to their published translation while saving Gutenberg content.
- Rewrites internal block URLs and links inside block markup to the corresponding translated post.
- Preserves query arguments and fragments, while external, email, telephone, missing, and unpublished destinations remain unchanged.
- Updates navigation-link entity IDs and URLs for translated posts and taxonomy terms.

### ACF and metadata

- Discovers supported ACF text fields in the translation editor.
- Saves translated ACF values independently on the translated post.
- Preserves ACF field-key references and structured values.
- Includes advanced per-post-type metadata policies:
  - `translate`
  - `copy`
  - `copy-once`
  - `ignore`

### Taxonomies

- Dedicated taxonomy translation screen.
- Translate term name, slug, and description.
- Filter the screen to one selected taxonomy at a time.
- Keep translated terms linked across languages.

### Menus and language switcher

- Assign a separate WordPress menu to each language and theme location.
- Create an empty translated menu and add only content from its language.
- Switch between menu languages directly in the native WordPress menu editor.
- Automatically insert the language selector at the beginning or end of selected menu locations.
- Configure the selector as a dropdown or a flat language list.
- Show flags, translated names, native names, or flags only.
- Include or hide the current language in list mode.
- Preview the selected appearance before saving.
- Open dropdowns by hover, keyboard focus, click, or touch.
- On individual content, show only languages that have a published translation of the current page, post, custom post, or taxonomy term.
- Keep the current language visible as a non-interactive indicator when no alternative translation is available.
- Uses isolated plugin styling so Divi and other themes cannot force oversized submenu presentation.
- Converts emoji flags to WordPress-compatible images when the operating system cannot render country flags.
- The `[sysopenlang_switcher]` shortcode remains available for manual placement.

### Strings and shortcodes

- Register and translate gettext/interface strings from themes and plugins.
- Optional discovery mode records strings while relevant pages are visited.
- Dedicated shortcode catalog and translation editor.
- Detects human-readable labels from rendered shortcode output without hardcoding individual shortcode names.
- Supports dynamically rendered shortcode output, including compatible JavaScript applications.

### SEO integrations

SysOpenLang detects and translates common SEO metadata while keeping it attached to the correct language version. Supported integrations include:

- Yoast SEO
- Rank Math
- All in One SEO (AIOSEO)
- SEOPress

Language URLs, canonical links, translated slugs, and `hreflang` output are generated for search-engine discovery. Indexing still depends on each page being public, crawlable, internally linked, and permitted by the site's SEO settings.

### Automatic translation providers

Site owners can configure their own credentials under **SysOpenLang → Advanced settings** and select one active provider:

- OpenAI
- Anthropic Claude
- Google Gemini
- Google Cloud Translation

Each provider tab includes setup instructions and official links for obtaining an API key. Credentials are encrypted using the WordPress authentication salt and are never displayed again after saving.

Automatic translation jobs:

1. Are queued from the translation editor.
2. Start through WordPress scheduling.
3. Translate standard content, Gutenberg blocks, Divi segments, supported ACF fields, and detected SEO metadata.
4. Preserve HTML, shortcodes, placeholders, URLs, numbers, units, and structured keys.
5. Remain available for human review before publication.
6. Show clear queued, translating, ready, and failed states under **SysOpenLang → Jobs**.

API usage is billed or limited by the selected provider. A ChatGPT or Claude subscription is separate from API usage.

Bundled country flag SVGs are a small, locally served subset of the MIT-licensed [flag-icons](https://github.com/lipis/flag-icons) project. Its license is included with the assets.

## Administration screens

- **SysOpenLang**: languages, URL format, selector basics, visibility, and browser behavior.
- **Interactive setup assistant**: a guided five-step experience for languages, URL structure, a live language-selector preview, translation workflow defaults, and a final review before saving.
- **Settings**: translation defaults, slug rules, source-change workflow, automatic-job limits, SEO, permissions, notifications, discovery, and maintenance.
- **Strings**: interface strings registered by WordPress themes and plugins.
- **Menus**: language-specific menu assignments and selector appearance.
- **Advanced settings**: translation providers and exceptional metadata policies.
- **Jobs**: automatic translation queue and retry/review actions.
- **Tools**: import and export SysOpenLang configuration and translation data.
- **Diagnostics**: plain-language site health for database tables, languages, providers, and background jobs.
- **Shortcodes**: discover and translate registered shortcode output.
- **Taxonomies**: edit translated term names, slugs, and descriptions.
- **Divi Theme Builder**: translate Divi headers, bodies, and footers.

## Installation

1. Copy the `sysopenlang` directory to `wp-content/plugins/`.
2. Activate **SysOpenLang** from the WordPress Plugins screen.
3. Open **SysOpenLang** and enable at least two languages.
4. Select the default language and URL format, then save.
5. Visit Pages, Posts, or a supported CPT and use the language icons to create translations.
6. Configure language-specific menus under **SysOpenLang → Menus**.
7. Optionally configure an automatic translation provider under **Advanced settings**.

After changing URL modes, SysOpenLang requests a rewrite-rule refresh automatically. If a local environment still returns stale routes, visit **Settings → Permalinks** and save once, then clear page or Divi caches.

## Public API and extension points

Core domain services live in `src/`. Optional capabilities live in `src/modules/` and implement `SysOpenLang\Contracts\Module`. Translation providers implement `SysOpenLang\Contracts\Translation_Provider`.

Useful PHP helpers include:

```php
SysOpenLang\register_string( $key, $text, $domain, $source_language );
SysOpenLang\translate_string( $key, $fallback, $domain, $language );
SysOpenLang\translated_post_id( $post_id, $language );
SysOpenLang\translated_term_id( $term_id, $language );
SysOpenLang\register_provider( $provider );
SysOpenLang\enqueue_translation_job( $source_id, $target_id, $language, $provider_id );
SysOpenLang\set_menu_translation( $location, $language, $menu_id );
```

Primary filters and actions:

- `sysopenlang_modules`
- `sysopenlang_translation_providers`
- `sysopenlang_meta_policy`
- `sysopenlang_workflow_statuses`
- `sysopenlang_woocommerce_shared_meta`
- `sysopenlang_modules_loaded`

See [Architecture](docs/ARCHITECTURE.md) and [Translation providers](docs/PROVIDERS.md) for implementation details.

## Data, privacy, and removal

SysOpenLang stores translation relationships, strings, and automatic-translation jobs in per-site WordPress tables. Provider credentials are stored encrypted in WordPress options.

No content is sent to an external translation service unless an administrator configures that provider and explicitly starts an automatic translation job. The exact provider receives only the segments included in that job.

Uninstall preserves multilingual data by default. Permanent cleanup requires an explicit choice plus confirmation in **SysOpenLang → Settings**, or the `SYSOPENLANG_REMOVE_DATA` constant for managed installations.

## Quality checks

Run the complete test suite:

```bash
php tests/run.php
php tests/modules.php
php tests/acf.php
php tests/divi.php
php tests/divi-theme-builder.php
php tests/seo.php
php tests/shortcodes.php
php tests/slugs.php
```

Every PHP file must also pass `php -l`. JavaScript files should pass `node --check`, and user-facing changes should be verified in the WordPress administrator and frontend.

## License

GPL-2.0-or-later.
