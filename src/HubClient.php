<?php

namespace McpHub\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use McpHub\Laravel\Support\Json;
use RuntimeException;
use Throwable;

final class HubClient
{
    private $requestId = 0;
    private $connection;
    private $http;

    public function __construct(Connection $connection, ?ClientInterface $http = null)
    {
        $this->connection = $connection;
        $this->http = $http ?: new Client();
    }

    public function rpc(string $method, array $params = [], array $headers = []): array
    {
        if (! in_array($method, ['tools/list', 'tools/call'], true)) {
            throw new RuntimeException('Unsupported Hub operation.');
        }
        $url = (string) $this->connection->get('url', '');
        $token = (string) $this->connection->get('client_key', '');
        $parts = parse_url($url);
        $local = app()->environment(['local', 'testing']) && in_array(strtolower($parts['host'] ?? ''),
            ['localhost', '127.0.0.1', '::1'], true);
        if (! $this->connection->get('enabled') || $token === '' || ! is_array($parts)
            || empty($parts['host']) || (($parts['scheme'] ?? '') !== 'https'
                && !(($parts['scheme'] ?? '') === 'http' && $local))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('MCP Hub connection is not configured.');
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
            $safeHeaders['Authorization'] = 'Bearer '.$token;
            $response = $this->http->request('POST', $url, [
                'headers' => $safeHeaders, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => max(1, min(120, (int) $this->connection->get('timeout', 12))),
                'body' => Json::encode(['jsonrpc' => '2.0', 'id' => ++$this->requestId, 'method' => $method,
                    'params' => $params === [] ? new \stdClass() : $params]),
            ]);
            $raw = $response->getBody()->read(1048577);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || strlen($raw) > 1048576) {
                throw new RuntimeException('Hub request failed.');
            }
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
            if (! is_array($body) || ($body['id'] ?? null) !== $this->requestId
                || isset($body['error']) || ! is_array($body['result'] ?? null)) {
                throw new RuntimeException('Invalid Hub response.');
            }
            return $body['result'];
        } catch (Throwable $error) {
            // Upstream errors may contain credentials or business data.
            throw new RuntimeException('MCP Hub request failed.');
        }
    }
}
