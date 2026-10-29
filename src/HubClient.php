<?php

namespace McpHub\Laravel;

use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

final class HubClient
{
    private $requestId = 0;
    private $connection;
    private $transport;

    public function __construct(Connection $connection, ?ClientInterface $http = null)
    {
        $this->connection = $connection;
        $this->transport = new HubTransport($connection, $http);
    }

    public function rpc(string $method, array $params = [], array $headers = []): array
    {
        if (! in_array($method, ['tools/list', 'tools/call'], true)) {
            throw new RuntimeException('Unsupported Hub operation.');
        }
        if ($method === 'tools/call') {
            $arguments = $params['arguments'] ?? [];
            if (! is_string($params['name'] ?? null) || ! preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $params['name'])
                || ! is_array($arguments) || array_filter(array_keys($arguments), 'is_int')) {
                throw new RuntimeException('Invalid Hub tool call.');
            }
            // PHP's empty array encodes as [], while MCP requires an object here.
            $params['arguments'] = $arguments === [] ? new \stdClass() : $arguments;
        }
        $version = (string) $this->connection->get('protocol_version');
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'laravel-mcp-hub', 'version' => '0.2.0-alpha.1']];
        $safeHeaders = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => $version,
            'MCP-Method' => $method, 'Origin' => (string) $this->connection->get('source_url')];
        if (isset($params['name'])) {
            $safeHeaders['MCP-Name'] = $params['name'];
        }
        if (isset($headers['X-Hub-Delegation'])) {
            $safeHeaders['X-Hub-Delegation'] = $headers['X-Hub-Delegation'];
        }
        try {
            $raw = $this->transport->send('POST', '', ['jsonrpc' => '2.0', 'id' => ++$this->requestId,
                'method' => $method, 'params' => $params === [] ? new \stdClass() : $params], $safeHeaders);
            $body = json_decode($raw, true);
            if (! is_array($body)) {
                // Streamable HTTP can return a JSON-RPC response in an SSE event.
                foreach (preg_split('/\r?\n\r?\n/', $raw) as $event) {
                    $data = [];
                    foreach (preg_split('/\r?\n/', $event) as $line) {
                        if (strpos($line, 'data:') === 0) {
                            $data[] = ltrim(substr($line, 5), ' ');
                        }
                    }
                    $candidate = json_decode(implode("\n", $data), true);
                    if (is_array($candidate) && ($candidate['id'] ?? null) === $this->requestId) {
                        $body = $candidate;
                        break;
                    }
                }
            }
            if (! is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0' || ($body['id'] ?? null) !== $this->requestId
                || isset($body['error']) || ! is_array($body['result'] ?? null)) {
                throw new RuntimeException('Invalid Hub response.');
            }
            return $body['result'];
        } catch (Throwable $error) {
            // Upstream errors may contain credentials or business data.
            if ($error instanceof RuntimeException && $error->getMessage() === 'MCP Hub connection is not configured.') {
                throw $error;
            }
            throw new RuntimeException('MCP Hub request failed.');
        }
    }
}
