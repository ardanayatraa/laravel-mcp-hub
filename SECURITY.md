# Security

The published package remains alpha; development features have not been released as stable. Application code remains responsible for active-account checks, tenant membership, record ownership and business authorization.

Keep credentials server-side and configure HTTPS in production. Development code requires the package's SQL nonce table for durable replay protection; source health refuses missing security metadata. The published alpha uses its earlier shared-cache replay implementation. The default resource registry is empty.

Development writes remain disabled until the application registers explicit policies, input schemas and handlers. Approval uses the exact application's signed-in actor and session CSRF. Record references are bound to the source, environment, resource, actor and tenant. Resource grants can only reduce developer permissions. See [development security boundaries](DEVELOPMENT.md).

Development exports are off by default and require an independent resource export permission. Jobs and authenticated downloads recheck the actor, tenant, current grants and record snapshot. Files remain encrypted in private application storage with bounded size and expiry. Chat constructs its own same-origin download route; model/source-supplied URLs are not rendered as download links. See [export security and operational limits](EXPORTS.md) and [upgrade/recovery](UPGRADE.md).

Report vulnerabilities privately through the repository's GitHub private vulnerability reporting feature when available. If it is unavailable, open an issue requesting a private reporting channel without including exploit details, credentials or personal data.

Only the latest alpha is maintained. Framework versions whose own security support has ended do not regain framework security support by installing this package.
