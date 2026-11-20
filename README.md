# vextra-core

Business-logic plugin for the Vextra WooCommerce store (theme: https://github.com/GLubomir/vextra).

Scope (ADR-001, ADR-003 in the theme repo): supplier import & stock sync, pricing/markup rules, order forwarding,
delivery/payment integrations, Polylang ↔ WooCommerce glue (Multilang), Rank Math/Polylang SEO adapters,
slug transliteration. Third-party APIs only through guarded adapter classes in `src/Integrations/`.

Branches: `main` (releases, tagged) · `develop` (integration) · `task/TASK-NNN-slug`.
Task specs and architecture decisions live in the theme repo (`docs/tasks/`, `docs/adr/`).

## Development

Requirements: PHP 8.1+, WordPress 7.1+, WooCommerce (`Requires Plugins`), Composer.

```
composer install     # dev tools + autoloader (without vendor/ the plugin still loads via a fallback autoloader and shows a notice)
composer lint        # phpcs: WPCS + PHPCompatibilityWP (8.1-)
composer lint:fix    # phpcbf
wp vextra status [--format=table|json]   # versions, modules, integrations
```

Branch model: `main` (tagged releases) <- `develop` <- `task/TASK-NNN-slug`. Executors commit on task branches only;
the Architect merges `--no-ff`. Specs/ADRs live in the theme repo.

**Add a module**: create `src/<Area>/<Name>.php` implementing `Contracts\ModuleInterface` (`id()`, `is_enabled()`,
`register()`), append the class to `Plugin::MODULES` (or use the `vextra/core/modules` filter). `is_enabled()` decides from
integrations so a missing Woo/Polylang never fatals; `register()` only adds hooks. Reference: `src/Health/Health.php`.

**Add an integration**: extend `Integrations\AbstractIntegration` in `src/Integrations/<Vendor>/`, implement `id()`,
`version()`, `detect()` (function/class/constant guards only), append to `Plugin::INTEGRATIONS`. Third-party API calls
live only in these classes (ADR-003). Modules use `Plugin::instance()->integration( 'id' )` and check `is_available()`.

Shared helpers: `Support\Cache` (versioned, language in key, off under WP-CLI unless `vextra/cache/allow_cli`),
`Support\Log` (WooCommerce logger, source `vextra-<channel>`, secrets scrubbed).

Deploy: `composer install --no-dev -o`. Deactivation never deletes data; `uninstall.php` removes only its own options.
