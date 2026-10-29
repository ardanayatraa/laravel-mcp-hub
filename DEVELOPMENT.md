# Unreleased development features

These features are implemented on the development branch. They are not in
`0.2.0-alpha.1` and have not been published as a stable release. The development
branch has passed the full PHP/Laravel CI matrix; every proposed release must
retain a passing workflow.

## Install and preserve the connection

```bash
php artisan mcp-hub:install --dry-run --migrate
php artisan mcp-hub:install --migrate
php artisan mcp-hub:connect --hub=https://hub.example.com --code=PAIRING_CODE
php artisan mcp-hub:status
php artisan mcp-hub:doctor --online
```

In production, migration execution additionally requires `--force`. The installer
runs only package migrations; review and back up the application's database first.
Existing configuration and credentials are preserved. Pairing asks the Hub for
read-only credentials and saves an encrypted installation proof before claiming
the one-use code. The source must be reachable from the Hub. The connection is
activated only after authenticated source health and contract checks pass.

If a response is lost, use `mcp-hub:connect --resume`. Do not repeatedly generate
credentials. If the original code expires or a claim was lost before credentials
were saved, an authorized Hub administrator can issue a replacement. Connect the
replacement with `--replace`; old credentials are revoked. `mcp-hub:sync` refreshes
the reviewed contract. `mcp-hub:disconnect` first disables the local connection;
offline revocation remains pending until the Hub acknowledges it. Status and
diagnostic output contain metadata only.

## Review resources and enable business operations

```bash
php artisan mcp-hub:discover
php artisan mcp-hub:discover --schema
php artisan make:mcp-resource orders --model='App\Models\Order'
```

Discovery inspects code and optional schema metadata without loading business
records. It does not publish access. The generator creates disabled, closed
resource definitions under `config/mcp-hub-resources` and application-owned policy
and command classes. Replace the generated denial rules with your application's
current actor, tenant, record and field rules before enabling the resource.

The default registry remains empty. A particular user model, billing model or
permission package is never required. Set `mcp-hub.actor_resolver` to an
application-owned `ActorResolver`. A `ResourcePolicy` scopes reads. A `WritePolicy`
also validates approved business input and supplies server-owned creation
attributes. These classes are the authority; the Hub cannot expand their grants.

Writes require all of the following: `commands.enabled`, an enabled resource and
operation, an explicit command handler, a closed scalar input schema, a permitted
Hub client key, and the authenticated actor's current policy. They are off by
default. `EloquentCommands` supplies transactional create/update/delete after
application-owned validation. Its preparation captures readable target fields
and the original record version; commit rechecks scope, permissions, reviewed
values and record version with a lock. Implement external side effects through
your application's transactional outbox. A database rollback cannot undo an
already-sent external request or an observer's external side effect.

| Method | Default source route | Result |
|---|---|---|
| POST | `/api/ai/v1/{resource}/commands/{operation}` | Expiring proposal; no business mutation |
| GET | `/api/ai/v1/{resource}/commands/{proposal}` | Actor-bound status receipt |

The user reviews canonical values on `/mcp-hub/proposals/{proposal}` within the
application's authenticated session. Approval and cancellation require session
CSRF and the exact proposal actor/tenant. Commit records business changes and the
idempotent receipt in one database transaction. Conflicting idempotency payloads,
expired proposals and revoked permissions are rejected. The private proposal
payload is cleared at a terminal state. Pending is never reported as committed.

Default record references are authenticated and encrypted, bound to the source
URL, environment, resource, actor and tenant. They never replace the policy's
record scope. `record_references=legacy` is an explicit compatibility option for
older `record_...` references; opaque references are the default.

## Application settings and chat

The protected `/mcp-hub` page manages connection checks and resource grants;
`/mcp-hub/chat` provides the server-side chat integration. Define the host Gates
named by `web.manage_gate` and `web.chat_gate` for your application's signed-in
users. Missing Gates deny access. Resource grants may only reduce developer
permissions. Routes use session authentication, explicit CSRF/origin checks,
bounded input, local assets and a strict content security policy. No Hub key,
delegation secret or provider key is embedded in browser HTML or JavaScript.

Use `HubClient` for MCP tools and `Gateway` for actor-bound models, completion,
chat configuration and chat turns. Hub model allowlists and budgets still apply.
Transport rejects redirects, bounds response bytes and time, and returns safe
error codes. Keep these clients server-side. Model-provider credentials remain
at the Hub.

## Metadata maintenance and readiness

Assertion nonces use a database uniqueness constraint, including across PHP
workers and cache resets. Run package migrations before connecting. The
authenticated source health route returns 503 if replay metadata is missing or
if enabled commands or exports lack their required metadata. Doctor checks the same state.

The provider registers an hourly `mcp-hub:prune` task with an overlap lock. It
expires private pending payloads, removes expired assertion nonces, and retains
terminal receipts for 30 days. The host's normal Laravel scheduler must already
run, for example through its existing `schedule:run` cron. The package does not
change server cron or cache settings. If the application owns scheduling, set
`maintenance.scheduled_prune=false` and schedule `mcp-hub:prune --days=30` itself.
The command accepts retention from 1 through 365 days and touches package tables
only.

## Remaining stable-release gates

Read [UPGRADE.md](UPGRADE.md) for preservation, metadata-only installation and
recovery. The full thirteen-row matrix and separate native PostgreSQL race checks
passed locally and in [hosted CI](https://github.com/ardanayatraa/laravel-mcp-hub/actions/runs/40856404324).
All thirteen hosted combinations collected 116 tests with two opt-in skips per
row; the native PostgreSQL job separately passed its 71 assertions. Chat assets
also passed. The changes are available in [PR #2](https://github.com/ardanayatraa/laravel-mcp-hub/pull/2).

[Private export/report downloads](EXPORTS.md) now include queued CSV/XLSX/PDF,
actor-bound expiry and current record/policy checks. A code-only legacy application
pilot preserved its connection/configuration and passed its native connector/client
suites. Independent PostgreSQL workers checked transactional approvals, version
conflicts, idempotency and export capacity. Publication branch review and the
stable tag remain outstanding. Review each host application's dependency audit
and vendor customizations before its own cutover. Local tests and simulated model
responses do not establish production capacity or restore upstream security support
for end-of-life PHP/Laravel versions.
