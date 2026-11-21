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

## Multilang module (Polylang free + WooCommerce)

Replaces the paid "Polylang for WooCommerce" (ADR-002 s11). Enabled only when WooCommerce and Polylang are ready;
flag filter `vextra/core/multilang/enabled`.

- **Woo pages per language**: shop/cart/checkout/my-account (+ terms, refund page when configured) map to the translation
  of the current language. RU twins keep the UA slug (`/ru/catalog/`, `/ru/cart/`...) through a narrow `wp_unique_post_slug`
  filter plus a `request` filter (language-aware `pagename` -> `page_id`). **Do not rename the four base slugs**: UA and RU
  slugs are shared by the filter, not linked. A rename = both pages plus a manual Rank Math redirect in both languages.
  Cart/checkout/my-account (all languages) are `noindex` and excluded from the Rank Math sitemap.
- **Synced between translation twins** (last save wins, default-language product is the authority for the CLI): prices,
  sale dates, stock fields, SKU (same SKU allowed inside one translation group only), weight/dimensions, tax, shipping class,
  sold-individually, virtual/downloadable, image + gallery, catalog visibility, featured, global (`pa_*`) attributes.
  Custom attributes are copied only into a twin that has none. Stock changes (orders, cancel, refund) propagate to twins.
- **Never synced**: title, slug, content, excerpt, categories/tags, Rank Math fields, reviews, variations (out of scope).
- **Orders**: `_vx_order_lang` order meta; order-bound emails are sent in the customer's locale.
- **hreflang**: `uk`, `ru-UA`, `x-default` = UA on translated, indexable, non-paged pages.

```
wp vextra multilang pages [--dry-run]                          # create missing RU Woo pages (idempotent)
wp vextra multilang sync-products [--ids=<ids>] [--dry-run]    # align twins from the UA products (batches of 50)
```

## Seo module (Rank Math + Polylang + WooCommerce)

Enabled only when all three are available; flag filter `vextra/core/seo/enabled`. Rank Math stays the only emitter of `<head>` and
JSON-LD; the module filters its data through `Integrations\RankMath` (all Rank Math hook names live there; every method is a no-op
when Rank Math is absent, filters fail open).

- `TermCounts`: hierarchical published-product count per `product_cat` (UA + RU) from one bulk SQL, cached in group `seo`,
  flushed on product/term writes; WP's direct-only `term.count` is never read. `Robots`: `product_cat` is `index` iff it has products
  in itself or a descendant and is not manually noindex (never touches `product_tag`, `pa_*`, search, or a private site).
- `Sitemap`: term sitemap in both languages (widens `get_terms` for sitemap requests only, entries filtered by the same rule),
  `/catalog/` listed once, stored term sitemap invalidated when the indexable set changes. `Multilang\Hreflang` prints category
  alternates only when the twin is indexable too.
- `Templates` (`vextra/seo/templates`), `Breadcrumbs` (home label/URL per language, `Каталог` crumb, product trail category),
  `Schema` (Product `name` = product name).

```
wp vextra seo status [--format=table|json|csv]   # per category: counts, robots result, in sitemap
wp vextra seo recount                             # Woo term recount + drop the SEO count cache
```


### SEO URL hygiene (TASK-009e)

- **Slugs** (`Seo\Urls` module -> `Slugs` + `Transliterator`; works without Polylang/Rank Math, flag `vextra/core/seo/slugs_enabled`): NEW terms
  (`product_cat`, `product_tag`, every `pa_*`) and NEW posts/pages/products get a latin slug when the title (or a typed slug) is Cyrillic.
  Tables: UA = Cabinet of Ministers No. 55 (2010) with positional `є ї й ю я` and `зг`->`zgh`; RU = registry of `docs/seo/ru-category-slugs.md`.
  Lowercase `[a-z0-9-]`, <=60 chars cut at a hyphen. Existing slugs and latin slugs are never touched. A suffix produced by the uniqueness
  check (`-2`, `-ru`) is logged (`vextra-seo`) so it can be fixed before indexation.
  **Import contract**: the table is chosen from the language of the object. Importers/CLI that insert BEFORE telling Polylang the language
  MUST pass it: filter `vextra/seo/slug_language` (`( string $lang, string $kind term|post, array $context )`) or a `lang` argument of
  `wp_insert_term()` / `wp_insert_post()`; otherwise a title made only of shared letters falls back to the default (UA) table.
  Order: filter, `lang` arg, admin form value (`term_lang_choice`/`post_lang_choice`), stored post language, letters `ы э ё ъ`/`і ї є ґ`, default.
- **Category 301** (`TermRedirects`): re-slug or move of a `product_cat` writes single-hop 301s (Rank Math Redirections) for the term and every
  descendant plus a `/page/N/` regex row; chains collapse, redirects whose source became live are removed, no-op saves write nothing, subtree cap 500.
  Redirects of this module are tracked in option `vx_seo_redirect_ids` (not autoloaded). Term DELETE is not handled (import task).
- **`pa_*`** archives: `noindex, follow`, never in a sitemap (`Attributes`).
- **Organization** (`Organization`): enriches Rank Math's single Organization node with `sameAs`, one `ContactPoint` (phone/email), `url`, `logo` from filter
  `vextra/seo/organization_data` (`same_as`, `telephone` E.164, `email`, `logo`); the theme answers it, an empty answer adds nothing. Home page = WebPage
  only (Article/Person author removed).
- **Sitemap**: `_vx_demo` pages/posts (front page and its translations excepted) and Rank Math noindex pages/posts are excluded; demo products stay.
- **Hreflang** on paginated shop/category archives: `/catalog/page/N/` <-> `/ru/catalog/page/N/`.

```
wp vextra seo transliterate "<text>" [--lang=uk|ru] [--max=60]   # pure, prints the slug
wp vextra seo redirects [--format=table|json|csv]                 # redirects written by TermRedirects
```