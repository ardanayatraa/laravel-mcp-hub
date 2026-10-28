# Laravel MCP Hub

Expose reviewed Laravel resources to an MCP Hub and call the Hub through a scoped server-side client. Applications retain control of business data, account identity, record ownership, and permissions.

**Alpha release:** 0.1.0-alpha.1. The generic connector is tested on Laravel 12. Its Hub transport has also been exercised in an existing Laravel 9 application. Laravel 5.8–8, 10–11, and 13 are not currently supported by the Composer constraints. Compatibility with future major versions is not implied.

There are **no business models registered by default**.

## Install

~~~bash
composer require ardanayatraa/laravel-mcp-hub:0.1.0-alpha.1
php artisan mcp-hub:install
~~~

If Packagist indexing is pending, configure the public VCS repository first:

~~~bash
composer config repositories.laravel-mcp-hub vcs https://github.com/ardanayatraa/laravel-mcp-hub
composer require ardanayatraa/laravel-mcp-hub:0.1.0-alpha.1
~~~

Laravel discovers the provider automatically unless the application disables package discovery. The installer publishes config/mcp-hub.php only when it does not already exist. It does not overwrite application files, run application migrations, create a Hub account, grant data access, or rotate credentials.

## Configure a connection

Provision the application in your Hub, then set these values in the application's environment or secret store:

~~~dotenv
MCP_HUB_APP_KEY=your_app
MCP_HUB_ENVIRONMENT=production
MCP_HUB_ENABLED=true
MCP_HUB_URL=https://hub.example.com/mcp
MCP_HUB_CLIENT_KEY=
MCP_HUB_BASE_URL=https://app.example.com
MCP_HUB_APP_TOKEN=
MCP_HUB_DELEGATION_SECRET=
~~~

The client key authenticates Laravel to the Hub. The app token authenticates the Hub to Laravel. The delegation secret signs short-lived actor context; use at least 32 random characters for the app token and delegation secret. Keep all credentials server-side.

MCP_HUB_BASE_URL is the Laravel source URL, not the Hub URL. The Hub must be able to reach it, and its network/origin policy must permit the connection. Local HTTP Hub calls are accepted only for loopback addresses in Laravel's local/testing environments.

~~~bash
php artisan mcp-hub:connect --import-env
php artisan mcp-hub:doctor
php artisan mcp-hub:doctor --online --json
~~~

Import saves an encrypted copy under storage/app/mcp-hub/connection.enc using the application's encryption key. Existing environment values take precedence. Do not remove environment configuration expecting a complete provisioning workflow: one-time pairing and a database-backed settings screen are future work.

## Register application-owned resources

The default config is intentionally empty:

~~~php
'actor_resolver' => \McpHub\Laravel\Security\DenyActorResolver::class,
'models' => [],
~~~

The application implements two contracts:

- ActorResolver converts an authenticated user into an Actor and resolves signed user references back to a currently active account and tenant.
- ResourcePolicy authorizes each read and applies the application's complete ownership/tenant scope to an Eloquent query.

An example application registration could look like this:

~~~php
'actor_resolver' => \App\Mcp\ActorResolver::class,
'models' => [
    'orders' => [
        'model' => \App\Models\Order::class,
        'policy' => \App\Mcp\OrderPolicy::class,
        'enabled' => true,
        'read' => true,
        'lookup' => 'title',
        'fields' => [
            'title' => 'string',
            'status' => 'string',
            'total' => 'number',
        ],
        'filters' => ['status' => ['paid', 'pending']],
    ],
],
~~~

These example classes belong to the installing application; the package does not provide or assume them. Model fields must be explicitly allowlisted. Hidden and sensitive field names are rejected. Pagination parameters limit and cursor are reserved and cannot be used as lookup or filter names.

You can replace Registry through the Laravel container to read your application's existing permission store. Register the Hub connector in source-managed mode so its tool catalog follows the current application manifest.

## Source API

The default prefix is /api/ai/v1:

| Method | Path | Purpose |
|---|---|---|
| GET | /health | Service-authenticated health |
| GET | /capabilities | Application-managed Connector Contract 1.0 manifest |
| GET | /{resource}/list | Authorized, paginated records |
| GET | /{resource}/get | Exact lookup using the registered field |
| GET | /{resource}/count | Count within the same authorized scope |

All routes require the configured bearer app token. Reads additionally require a signed X-Hub-Assertion carrying the approved app key, audience, active user reference, tenant, expiry, and one-use jti.

Production needs a shared cache with atomic add for replay rejection. In-memory array/null caches are refused for actor verification outside local/testing. Source responses are marked no-store.

If the application already owns these routes, set routes.enabled to false and adapt its existing source registry and gateway rather than registering duplicate endpoints.

The generic alpha exposes reads only. Application-specific write proposals, confirmations, chat UI, report exports, provider credentials and business rules are outside this package.

## Hub transport

~~~php
$client = app(\McpHub\Laravel\HubClient::class);
$catalog = $client->rpc('tools/list');

$delegation = app(\McpHub\Laravel\Security\Assertions::class)->delegate($user);
$result = $client->rpc('tools/call', [
    'name' => 'your_app__orders__list',
    'arguments' => ['limit' => 20],
], ['X-Hub-Delegation' => $delegation]);
~~~

The client supports tools/list and tools/call, bounded connect/read timeouts, JSON and SSE responses, response-ID checks, and sanitized errors. Its protocol metadata targets the current Hub transport; it is not a general-purpose client for arbitrary MCP servers.

## Development

~~~bash
composer install
composer test
composer validate --strict
~~~

The standalone Testbench suite uses Laravel 12 and an isolated in-memory SQLite database. CI runs that suite on PHP 8.2, 8.3, and 8.4. Laravel 9 integration has separate local evidence; fresh Laravel 9 resolution is blocked by framework security advisories. No security or platform checks are disabled by this repository.

For an actual HTTP transport check, start the local Postman simulation:

~~~bash
postman simulation run postman/simulations/connector.sim.yaml --port 18130 --output ndjson
~~~

Set MCP_HUB_SIM_URL=http://127.0.0.1:18130/hub/mcp before running composer test. The mock validates the dummy caller and protocol metadata and models tools/list only; other calls return 501. A passing mock run is not a live Hub or model-completion test.

## License

MIT. See [LICENSE](LICENSE).
