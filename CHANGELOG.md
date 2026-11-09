# Changelog

## 1.0.1 - 2026-11-09

- Accept Guzzle 8 as well as Guzzle 7, so `composer require ardanayatraa/laravel-mcp-hub:^1.0` installs on a fresh Laravel 13 application without `-W`. Laravel 13 matrix rows now resolve and assert Guzzle 8; older rows keep Guzzle 7.
- Stop sending an `Origin` header on server-to-server Hub calls. Hubs no longer need the Laravel source URL in `ALLOWED_ORIGINS` for MCP calls to succeed after pairing.
- Declare the response buffer's stream property, removing the PHP 8.2+ dynamic property deprecation on every Hub call.
- Give the package's rate limits their own counters (`mcp-hub-source-`, `mcp-hub-web-`) so they no longer share the per-address counter with the host's unprefixed `throttle` middleware (Laravel 6+). The source limit is configurable with `routes.throttle` / `MCP_HUB_SOURCE_THROTTLE` and now defaults to 120 requests per minute for all Hub calls together.
- Render chat answers with a safe Markdown subset (paragraphs, tables, lists, headings, bold and code) built from DOM nodes; model text never becomes HTML and links stay plain text.
- Report the package release as the MCP client version.

## 1.0.0 - 2026-11-03

- Add source-bound one-use pairing, encrypted resumable installation proof, scoped sync/status/disconnect and replacement support.
- Add transactional CRUD proposals, canonical previews, application session/CSRF approval, version/scope revalidation and idempotent status receipts.
- Replace cache-only replay checks with durable SQL uniqueness; add opaque actor/tenant/source/environment-bound record references.
- Add protected application settings/chat, a scoped Gateway SDK, bounded transport, safe errors and English/Indonesian approval pages.
- Add metadata-only resource discovery, a disabled resource generator, package-only migration previews and shared Doctor checks.
- Register hourly package metadata pruning through the host Laravel scheduler and refuse source readiness when required security metadata is absent.
- Keep every business model and business permission application-owned, with empty package defaults.
- Add private queued CSV/XLSX/PDF reports, bounded grouped metrics, encrypted artifacts, expiry and current actor/policy/record checks on download.
- Build chat download links from validated source references; neutralize spreadsheet formulas and disable PDF remote resources, PHP and JavaScript.
- Validate generated tool/input limits and private enum choices before manifest publication; run only package migration files without restoring an application schema dump.
- Add native PostgreSQL race checks and upgrade/recovery guidance. The local and hosted thirteen-row matrices, native PostgreSQL and chat asset jobs passed. Distribution checks validate the archive contents and exercise the extracted runtime before publication.

## 0.2.0-alpha.1 - 2026-10-28

- Retain legacy support in the same Composer package across Laravel 5.8 through 13.
- Replace PHP 8 syntax with PHP 7.2-compatible implementation; minimum runtime PHP 7.2.5.
- Use Guzzle 7.15.2+ directly for a shared HTTP transport across all supported frameworks.
- Preserve configuration keys, source contract, actor policies, replay protection and empty default resources.
- Add a 13-row PHP/framework matrix with scoped read, authorization, transport and setup-command checks.
- Keep historical upstream advisory exceptions in isolated development fixtures with visible audit reports.

## 0.1.0-alpha.1 - 2026-10-28

- Initial public alpha with an empty default resource registry.
- Authenticated health, manifest and scoped read routes.
- Application-owned actor resolution and resource policies.
- Signed actor context and one-use assertion replay checks.
- Scoped Hub HTTP transport with JSON/SSE parsing and bounded timeouts.
- Non-overwriting configuration installer, encrypted connection import and diagnostics.
- Standalone Laravel 12 tests and a local transport simulation.

One-time pairing, a generic settings UI, writes and the broader Laravel compatibility matrix are not included in this release.
