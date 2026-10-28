<?php

namespace McpHub\Laravel\Tests;

use McpHub\Laravel\HubClient;

class HubSimulationTest extends TestCase
{
    public function test_actual_transport_calls_the_local_hub_simulation(): void
    {
        if (! getenv('MCP_HUB_SIM_URL')) {
            $this->markTestSkipped('Run the local Postman simulation and set MCP_HUB_SIM_URL.');
        }
        config(['mcp-hub.enabled' => true, 'mcp-hub.url' => getenv('MCP_HUB_SIM_URL'),
            'mcp-hub.client_key' => 'dummy-client-key', 'mcp-hub.source_url' => 'http://source.test']);
        $this->assertSame(['tools' => []], app(HubClient::class)->rpc('tools/list'));
    }
}
