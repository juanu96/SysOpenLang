<?php
/**
 * Plugin Name: OpenLingua
 * Plugin URI:  https://github.com/juanu96/OpenLingua
 * Description: Multilingual content, custom post types, custom fields and strings for WordPress.
 * Version:     1.20.35
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author:      OpenLingua Contributors
 * Author URI:  https://profiles.wordpress.org/juanu96/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: openlingua
 */

/**
 * Copyright (C) 2026 OpenLingua Contributors
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'OPENLINGUA_VERSION', '1.20.35' );
define( 'OPENLINGUA_FILE', __FILE__ );
define( 'OPENLINGUA_DIR', plugin_dir_path( __FILE__ ) );

require_once OPENLINGUA_DIR . 'src/class-database.php';
require_once OPENLINGUA_DIR . 'src/contracts/interface-module.php';
require_once OPENLINGUA_DIR . 'src/contracts/interface-translation-provider.php';
require_once OPENLINGUA_DIR . 'src/contracts/interface-content-extractor.php';
require_once OPENLINGUA_DIR . 'src/class-module-registry.php';
require_once OPENLINGUA_DIR . 'src/class-languages.php';
require_once OPENLINGUA_DIR . 'src/class-translations.php';
require_once OPENLINGUA_DIR . 'src/class-content.php';
require_once OPENLINGUA_DIR . 'src/class-gutenberg-content.php';
require_once OPENLINGUA_DIR . 'src/class-global-content.php';
require_once OPENLINGUA_DIR . 'src/class-divi-content.php';
require_once OPENLINGUA_DIR . 'src/class-divi-theme-builder.php';
require_once OPENLINGUA_DIR . 'src/class-acf-content.php';
require_once OPENLINGUA_DIR . 'src/class-elementor-content.php';
require_once OPENLINGUA_DIR . 'src/class-structured-meta-content.php';
require_once OPENLINGUA_DIR . 'src/class-content-extractors.php';
require_once OPENLINGUA_DIR . 'src/class-translation-memory.php';
require_once OPENLINGUA_DIR . 'src/class-translation-editor.php';
require_once OPENLINGUA_DIR . 'src/class-taxonomies.php';
require_once OPENLINGUA_DIR . 'src/class-strings.php';
require_once OPENLINGUA_DIR . 'src/class-shortcode-content.php';
require_once OPENLINGUA_DIR . 'src/class-shortcode-admin.php';
require_once OPENLINGUA_DIR . 'src/class-routing.php';
require_once OPENLINGUA_DIR . 'src/class-seo.php';
require_once OPENLINGUA_DIR . 'src/class-rest.php';
require_once OPENLINGUA_DIR . 'src/class-graphql.php';
require_once OPENLINGUA_DIR . 'src/class-admin.php';
require_once OPENLINGUA_DIR . 'src/class-plugin.php';
require_once OPENLINGUA_DIR . 'src/modules/class-workflow.php';
require_once OPENLINGUA_DIR . 'src/modules/class-language-catalog.php';
require_once OPENLINGUA_DIR . 'src/modules/class-language-settings.php';
require_once OPENLINGUA_DIR . 'src/modules/class-site-settings.php';
require_once OPENLINGUA_DIR . 'src/modules/class-menus.php';
require_once OPENLINGUA_DIR . 'src/modules/class-media.php';
require_once OPENLINGUA_DIR . 'src/modules/class-metadata.php';
require_once OPENLINGUA_DIR . 'src/modules/class-commerce.php';
require_once OPENLINGUA_DIR . 'src/modules/class-providers.php';
require_once OPENLINGUA_DIR . 'src/modules/class-openai-provider.php';
require_once OPENLINGUA_DIR . 'src/modules/trait-provider-secrets.php';
require_once OPENLINGUA_DIR . 'src/modules/class-anthropic-provider.php';
require_once OPENLINGUA_DIR . 'src/modules/class-gemini-provider.php';
require_once OPENLINGUA_DIR . 'src/modules/class-google-translate-provider.php';
require_once OPENLINGUA_DIR . 'src/modules/class-jobs.php';
require_once OPENLINGUA_DIR . 'src/modules/class-string-discovery.php';
require_once OPENLINGUA_DIR . 'src/modules/class-portability.php';
require_once OPENLINGUA_DIR . 'src/modules/class-diagnostics.php';
require_once OPENLINGUA_DIR . 'src/modules/class-privacy.php';
require_once OPENLINGUA_DIR . 'src/modules/class-cli.php';

register_activation_hook( __FILE__, array( 'OpenLingua\\Database', 'activate' ) );
add_action( 'plugins_loaded', array( 'OpenLingua\\Plugin', 'boot' ) );
