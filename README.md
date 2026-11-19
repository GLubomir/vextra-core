# vextra-core

Business-logic plugin for the Vextra WooCommerce store (theme: https://github.com/GLubomir/vextra).

Scope (ADR-001, ADR-003 in the theme repo): supplier import & stock sync, pricing/markup rules, order forwarding,
delivery/payment integrations, Polylang ↔ WooCommerce glue (Multilang), Rank Math/Polylang SEO adapters,
slug transliteration. Third-party APIs only through guarded adapter classes in `src/Integrations/`.

Branches: `main` (releases, tagged) · `develop` (integration) · `task/TASK-NNN-slug`.
Task specs and architecture decisions live in the theme repo (`docs/tasks/`, `docs/adr/`).
