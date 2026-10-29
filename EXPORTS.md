# Private report exports (development branch)

This feature is not in the published alpha. It adds CSV, XLSX and PDF files owned
by the Laravel application. The Hub receives an export receipt, never the file or
its business rows. Defaults enable no exports and assume no application models.

## Enable a reviewed resource

Keep your existing configuration and add these keys to `config/mcp-hub.php`:

```php
'exports' => ['enabled' => true, 'ttl' => 600, 'connection' => 'database', 'queue' => 'default'],
// Inside your existing 'web' array:
'export_gate' => 'mcp-hub.export',
```

Add `'exports' => ['csv', 'xlsx', 'pdf']` to an already reviewed resource
definition. Choose only the required formats. Its `ResourcePolicy::allows()`
must explicitly allow both `list` and `export` for the current actor. Its
`scope()` must enforce full ownership and tenant access. Register the named
`mcp-hub.export` Gate using your application's permissions; there is no default
grant. `ActorResolver::resolve()` must reload account status and tenant
membership when background workers resolve an actor.

The package never assumes a user model, billing model, permission column or role.
Administrator resource grants can reduce the developer's readable field list;
exports cannot add fields beyond that list. PII checks also run on each value.

Run `php artisan mcp-hub:install --migrate` after reviewing the package migration.
Use `--force` in production after your normal backup/review. This command runs
only package metadata migrations and preserves existing configuration and
credentials. Configure the host application's queue tables/backend separately.

## Queue and private storage

Run the application's normal queue worker, for example:

```sh
php artisan queue:work database --queue=default --tries=1 --timeout=60
```

Production refuses the `sync` driver. Set the queue backend's `retry_after` above
the worker timeout (for example 90 seconds). A worker claims each pending job
once. A crashed/timed-out worker leaves an unavailable job until expiry; request
a new export with a new idempotency key rather than replaying its job manually.
Queue failures expose only a static failed receipt.

Web and queue workers must share the same database, APP_KEY, application config,
and private `storage/app/mcp-hub` filesystem. The package uses a filesystem lock
to serialize preparation and enforce capacity. It is not intended for workers
with independent local storage. Files are encrypted at rest, atomically written
with private permissions, and never stored on a public disk. Do not publish or
symlink this directory. The queue payload contains only an opaque export ref.

TTL is bounded to 30–900 seconds (default 600). At most two active exports per
actor and forty per installation are admitted. Expired jobs cannot be downloaded,
even before cleanup. `mcp-hub:prune` deletes expired files and clears encrypted
identity/snapshot payloads, then deletes metadata older than one day. The package
registers hourly pruning; the host must run Laravel's scheduler. Prune outstanding
artifacts before an APP_KEY change and restart workers after code/config changes.

## Source API contract

| Method | Route under `routes.prefix` | Purpose |
| --- | --- | --- |
| POST | `/{resource}/exports` | Queue a bounded private report |
| GET | `/{resource}/exports/{export_ref}` | Check the same actor's job |

Both endpoints require the service bearer token and a fresh, signed one-use
`X-Hub-Assertion`. POST additionally requires an `Idempotency-Key` of 16–128
letters/digits/underscores/hyphens. Reusing a key with different input returns
409. The Hub generates a payload-bound key for each POST within a trace and never
retries POST requests automatically. A read-only Hub client key may call these
read endpoints, but Laravel's independent export policy is still required.

POST accepts a closed JSON object, no query string, up to 4 KiB:

```json
{"format":"xlsx","columns":"title,status,total","limit":200,"status":"paid"}
```

`format` is required. `columns` is a comma-separated list of up to ten reviewed
fields, without spaces or duplicates. The default is the first ten readable
fields. Existing developer-reviewed enum filters may be included. `limit` is
1–500, default 200. PDF defaults to 100 rows and refuses larger limits.

For aggregates, omit `columns` and choose `metric`: `count`, `sum`, `avg`, `min`,
or `max`. Numeric metrics require a readable numeric `field`. An optional
`group_by` lists at most two readable fields:

```json
{"format":"csv","metric":"sum","field":"total","group_by":"status","limit":500}
```

Aggregates cover only the bounded selected records. `coverage: partial` is never
a complete population total. Empty count is zero; empty numeric aggregates are
null. Reports include coverage and creation time in UTC. Time buckets, chart
templates, arbitrary SQL, formulas and unreviewed filters are not accepted by
this export endpoint. Hub `run_report` retains its existing analytics contract.

Success uses the usual `success/data/meta` source envelope. The single data row
contains `export_ref`, `status` (`pending`, `building`, `ready`, `failed`,
`expired`), `format`, `expires_at`, `coverage`, `row_count`, and `download_url`.
Its internal `tenant_id` is removed by the Hub's output guard. Only `ready` has a
download URL. URLs come from the trusted configured source URL, never an incoming
Host header, and must fit the Hub's 512-character output bound.

Readiness returns 503 when enabled export metadata columns are missing. Disabled
formats/resources are absent from the manifest. Source-managed synchronization
adds the `export` and `export_status` read tools; a scoped MCP profile must
explicitly include them to expose them to its client keys.

## Download authorization

The web route is `/{web.prefix}/exports/{export_ref}`. It requires the host's
signed-in session, the export Gate, a fresh resolved actor, the same app/source/
environment/tenant identity, current resource permissions and unchanged readable
field/format grants. A reference or link alone grants no access. Another actor
receives 404; expired downloads receive 410. Changed data or visibility returns
409 and requires a new export. Files are attachments with no-store/nosniff headers.

Before download, the application re-runs the bounded scoped query and verifies
its record/value/version snapshot. Deleted or moved records, policy restrictions,
changed values, and changed limit coverage invalidate the old artifact. Nothing
is refreshed silently. Access is evaluated at the download request; normal
in-flight request semantics apply to a concurrent policy change.

The package chat widget uses structured tool-result export receipts and builds
its own same-origin download route. It never renders a URL from model prose or
uses a source-supplied URL as an anchor. Pending exports can be checked with the
`export_status` tool in a later turn after the worker finishes.

## File safety and limits

CSV neutralizes spreadsheet formula prefixes, including leading whitespace and
format controls. XLSX uses literal inline strings, typed numeric/boolean cells,
fixed ZIP members and no formulas, macros or external relationships. It does not
require ext-zip. PDF uses [Dompdf](https://github.com/dompdf/dompdf) with remote
resources, PHP and JavaScript disabled, a private chroot, and escaped values in a
fixed table template. `ext-dom` and `ext-mbstring` are required by the renderer.
The bundled DejaVu font covers common Latin, Greek and Cyrillic text; additional
scripts need a reviewed font/template extension before being advertised.

Artifacts are at most 5 MiB; HTML input to PDF is at most 1 MiB. Each exported
value passes the same type/privacy guard as source reads. Large reports and
arbitrary rich templates require a separate reviewed application-owned handler.
