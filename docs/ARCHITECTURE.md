# SysOpenLang architecture

## Boundaries

The core owns language resolution, translation groups, content and term relationships, routing, SEO, strings, REST discovery, administration, and database lifecycle. Modules add optional behavior without modifying those domain services.

```text
sysopenlang.php
  -> core services in src/
  -> Module_Registry
       -> modules in src/modules/
       -> third-party modules through sysopenlang_modules
```

Translation groups use UUIDs and contain at most one element for each element type and language. Content and terms share the same repository while retaining different `element_type` values.

## Metadata policies

Policies are resolved by post type and meta key:

- `translate`: seed the target once and allow independent editing.
- `copy-once`: seed the target once without later synchronization.
- `copy`: seed and synchronize the value from the edited source.
- `ignore`: never copy the value.

The `sysopenlang_meta_policy` filter is the integration point for ACF, builders, and commerce extensions. An ACF field-key reference such as `_hero_title` is copied when `hero_title` has an explicit policy.

## Data lifecycle

SysOpenLang owns three per-site tables: translation relationships, strings, and translation jobs. Database upgrades run when the stored schema version differs from the plugin version. Uninstall preserves data unless `SYSOPENLANG_REMOVE_DATA` is explicitly true.

## Extension points

- `sysopenlang_modules`: add a class implementing `Contracts\Module`.
- `sysopenlang_translation_providers`: register provider objects.
- `sysopenlang_meta_policy`: customize field behavior.
- `sysopenlang_workflow_statuses`: customize editorial states.
- `sysopenlang_woocommerce_shared_meta`: customize shared product data.
- `sysopenlang_modules_loaded`: run after all modules register hooks.

## Security principles

Mutating admin actions require nonces and capabilities. Public REST routes expose only publicly viewable content. Provider jobs require edit access to both source and target. Imports merge data and do not delete content. External translation is always explicit and provider-controlled.
