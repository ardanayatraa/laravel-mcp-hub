<?php

return [
    'app_key' => env('MCP_HUB_APP_KEY'),
    'environment' => env('MCP_HUB_ENVIRONMENT'),
    'enabled' => env('MCP_HUB_ENABLED'),
    'url' => env('MCP_HUB_URL'),
    'client_key' => env('MCP_HUB_CLIENT_KEY', env('MCP_HUB_TOKEN')),
    'source_url' => env('MCP_HUB_BASE_URL', env('MCP_HUB_SOURCE_URL', env('APP_URL'))),
    'app_token' => env('MCP_HUB_APP_TOKEN', env('MCP_HUB_SOURCE_KEY')),
    'delegation_secret' => env('MCP_HUB_DELEGATION_SECRET'),
    'require_https' => env('MCP_HUB_REQUIRE_HTTPS', env('APP_ENV') === 'production'),
    'timeout' => (int) env('MCP_HUB_TIMEOUT', 12),
    'chat_timeout' => (int) env('MCP_HUB_CHAT_TIMEOUT', 120),
    'protocol_version' => '2026-07-28',
    'routes' => ['enabled' => true, 'prefix' => 'api/ai/v1'],
    'commands' => ['enabled' => false, 'ttl' => 300],
    'exports' => ['enabled' => false, 'ttl' => 600, 'connection' => null, 'queue' => 'default'],
    'maintenance' => ['scheduled_prune' => true],
    'web' => ['enabled' => true, 'prefix' => 'mcp-hub', 'guard' => null,
        'manage_gate' => 'mcp-hub.manage', 'chat_gate' => 'mcp-hub.chat', 'export_gate' => 'mcp-hub.export'],
    'actor_resolver' => \McpHub\Laravel\Security\DenyActorResolver::class,
    'resource_grants' => null,
    'record_references' => 'opaque',
    // Application-owned definitions only. No model, user class, or permission is assumed.
    'models' => [],
];
