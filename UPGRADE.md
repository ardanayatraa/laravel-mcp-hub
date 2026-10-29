# Upgrade and recovery

The published release remains `0.2.0-alpha.1`. Pairing, durable nonce/command/export
metadata and the protected application UI described here belong to the development
branch. Install a reviewed release containing those features before using them.
Keep one package name across Laravel versions; Composer resolves PHP/framework
requirements from the application. There is no Laravel-version-specific package.

## Preserve application ownership

Before an upgrade, retain the application's normal database backup, lock file,
existing `config/mcp-hub.php`, its resource definitions, and its private
`storage/app/mcp-hub` directory. Keep APP_KEY and connection credentials under the
application's existing secret management. Never put those files into a package
archive or source repository. Preserve private storage outside rotating releases.

Existing configuration takes precedence over package defaults. Installation never
overwrites a configuration file that already exists. Models remain empty by
default, and writes/exports remain off until the developer explicitly enables them.
Review new optional keys rather than replacing the application's entire config.
Generated `app.resource.action` tool keys must fit the Hub's 80-character limit.
Manifest generation refuses an oversized combination without truncating or
renaming existing keys; use concise registered keys when adding new operations.

If the application already owns its connector routes, keep `routes.enabled=false`.
Retain its resource registry, authorization, actor resolver, business handlers,
source routes and UI until equivalent behavior has been verified. Package clients
can be adopted independently. Both generations use the same encrypted connection
file format; pinned environment configuration still takes precedence.

## Apply reviewed metadata changes

Build dependencies using the application's normal locked release process. Review
dependency conflicts and audits; do not use ignore-platform flags, disable
security blocking or rewrite application credentials to make an install pass.
For the development branch, use the documented local path fixture rather than
pretending a stable release already exists.

Inspect the installation plan first:

```sh
php artisan mcp-hub:install --dry-run --migrate
```

After your database review/backup, install package metadata:

```sh
php artisan mcp-hub:install --migrate
# Production requires the explicit reviewed migration flag:
php artisan mcp-hub:install --migrate --force
php artisan mcp-hub:doctor
php artisan mcp-hub:status
```

Only the package's migration files are passed to Laravel's migrator. If needed,
Laravel's migration ledger is created first. This bypasses the application's
global `migrate` command and schema-dump restoration; application migration files
are never run by this command. Metadata tables are `mcp_hub_nonces`,
`mcp_hub_commands` and `mcp_hub_exports`. They contain no application model schema
changes. Health/doctor fail closed when an enabled feature lacks required columns.

Restart application queue/long-running workers through the application's normal
deployment process after code/config changes. Keep web and queue workers on the
same APP_KEY, database and private filesystem. The package does not change cron,
process-manager configuration, server environment or a public storage disk.

## Verify before switching traffic

Use synthetic accounts and records to verify both actor/tenant boundaries,
immediate permission revocation, schema/field restrictions, and source readiness.
Verify any application-owned integrations separately. A migration that keeps
native chat/proposals/reports must retain their existing host authorization and
test coverage; installing a generic library does not recreate business policy.

Check pairing resume/replacement without changing the application's source URL,
identity or environment. Pinned credentials must match a replacement bundle;
pairing cannot silently replace operator-managed values. Use `mcp-hub:disconnect`
for an explicit disconnect and retry revocation if it reports a pending remote
revoke. Renewal/replacement also remain visible in the Hub's application keys tab.

Test new commands only with disposable records. Only `committed` means a write
succeeded. Pending receipts from an older development snapshot may need to be
prepared again after source/environment binding or policy changes. Do not copy
pending metadata between installations or environments to resume approvals.

For exports, verify a real queue worker, expiry, wrong-user denial, policy/data
changes and file headers. A shared download link grants no access on its own.
Pruning belongs to the host scheduler. See [EXPORTS.md](EXPORTS.md) for limits.

## Recovery

If a build or metadata check fails, keep the previous working release and its
credentials/config/private storage. Restore the reviewed lock and release code
through the application's existing deployment process. Never run a database
downgrade automatically to make old code start. Newly created package metadata
may remain unused until the cause is reviewed; do not drop business tables.

If credentials were explicitly replaced/revoked, rolling back files does not
restore those credentials. Complete the reviewed pairing recovery flow instead.
Keep database/APP_KEY/private storage backups consistent. An APP_KEY change needs
its own recovery plan; this package does not rotate it during install or deploy.

If someone edited application files during a pilot migration, retain those edits
and review the saved diffs. Restore only files whose current hashes still match
the migration's own writes. Never restore `.env` or whole application directories
from a prototype overlay to resolve a dependency problem.

## Compatibility evidence

The development suite was exercised locally across all thirteen declared
PHP/Laravel combinations, including actual PHP 7.2/7.3/8.0/8.4 containers and native
PHP 7.4/8.1/8.2/8.3 runtimes. Separate PostgreSQL workers verified duplicate commits,
record-version conflicts, idempotency and export capacity. The workflows repeat
those checks when a reviewed branch is pushed. Local verification is distinct
from hosted CI, production capacity or an aaPanel deployment.

Historical framework dependencies retain known upstream advisories. The
exceptions used in isolated development fixtures are not installed into consumer
applications and do not restore upstream maintenance. Review the host audit
before a production upgrade, including any manual changes inside vendor packages.
