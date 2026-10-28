# Changelog

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
