<?php

namespace McpHub\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use McpHub\Laravel\Support\BoundedStream;
use McpHub\Laravel\Support\Json;
use RuntimeException;
use Throwable;

final class HubTransport
{
    private $connection;
    private $http;

    public function __construct(Connection $connection, ?ClientInterface $http = null)
    {
        $this->connection = $connection;
        $this->http = $http ?: new Client();
    }

    public function send(string $method, string $path = '', array $body = [], array $headers = [], int $timeout = 0): string
    {
        $url = (string) $this->connection->get('url', '');
        $token = (string) $this->connection->get('client_key', '');
        $this->validateUrl($url);
        if (! $this->connection->get('enabled') || $token === '') {
            throw new RuntimeException('MCP Hub connection is not configured.');
        }
        $paths = [
            '' => 'POST', '/gateway/v1/models' => 'GET', '/gateway/v1/chat/completions' => 'POST',
            '/chat/v1/config' => 'GET', '/chat/v1/turn' => 'POST',
        ];
        if (! isset($paths[$path]) || $paths[$path] !== $method) {
            throw new RuntimeException('Unsupported Hub endpoint.');
        }
        if ($path !== '') {
            if (substr($url, -4) !== '/mcp') {
                throw new RuntimeException('The Hub MCP URL must end with /mcp for gateway calls.');
            }
            $url = substr($url, 0, -4).$path;
        }
        $safe = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$token, 'Origin' => (string) $this->connection->get('source_url')];
        foreach (['MCP-Protocol-Version', 'MCP-Method', 'MCP-Name', 'X-Hub-Delegation'] as $name) {
            if (isset($headers[$name]) && is_string($headers[$name])) {
                $safe[$name] = $headers[$name];
            }
        }
        return $this->exchange($method, $url, $body, $safe, $timeout);
    }

    /** Server-side provisioning transport; caller supplies its own scoped proof.
     * This does not add the runtime client key to provisioning requests.
     */
    public function exchange(string $method, string $url, array $body = [], array $safe = [], int $timeout = 0): string
    {
        $local = $this->validateUrl($url);
        if (! in_array($method, ['GET', 'POST'], true)) {
            throw new RuntimeException('Unsupported Hub request method.');
        }
        $safe += ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        $timeout = max(1, min(120, $timeout ?: (int) $this->connection->get('timeout', 12)));
        $deadline = microtime(true) + $timeout;
        $sink = new BoundedStream(1048576, $deadline);
        $response = null;
        try {
            $payload = $method === 'POST' ? Json::encode($body) : null;
            if ($payload !== null && strlen($payload) > 1048576) {
                throw new RuntimeException('Hub request is too large.');
            }
            $options = ['headers' => $safe, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => $timeout, 'read_timeout' => $timeout, 'sink' => $sink,
                'on_headers' => function ($response) {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (! ctype_digit($length) || (float) $length > 1048576)) {
                        throw new RuntimeException('Hub response is too large.');
                    }
                }];
            if ($local) {
                $options['proxy'] = '';
            }
            if ($payload !== null) {
                $options['body'] = $payload;
            }
            $response = $this->http->request($method, $url, $options);
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $raw = '';
            while (! $stream->eof()) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Hub response timed out.');
                }
                $chunk = $stream->read(8192);
                if (($chunk === '' && ! $stream->eof()) || strlen($raw) + strlen($chunk) > 1048576) {
                    throw new RuntimeException('Hub response exceeded its limits.');
                }
                $raw .= $chunk;
            }
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $errorBody = json_decode($raw, true);
                $code = is_array($errorBody) && is_array($errorBody['error'] ?? null) ? ($errorBody['error']['code'] ?? null) : null;
                $allowed = ['UNAUTHENTICATED', 'PERMISSION_DENIED', 'RATE_LIMITED', 'CHAT_DISABLED',
                    'MODEL_NOT_ALLOWED', 'MODEL_ACCESS_UNCONFIGURED', 'TOKEN_BUDGET_UNCONFIGURED',
                    'TOKEN_BUDGET_EXCEEDED', 'BUDGET_EXCEEDED', 'CONNECTOR_INACTIVE', 'PII_DETECTED',
                    'VALIDATION_FAILED', 'UPSTREAM_TIMEOUT', 'UPSTREAM_UNAVAILABLE', 'UNAVAILABLE', 'CONFLICT'];
                $code = is_string($code) && in_array($code, $allowed, true) ? $code : 'UPSTREAM_UNAVAILABLE';
                throw new HubException($code, $response->getStatusCode());
            }
            return $raw;
        } catch (Throwable $error) {
            throw $error instanceof HubException ? $error : new HubException();
        } finally {
            if ($response !== null) {
                $response->getBody()->close();
            }
            $sink->close();
        }
    }

    public function validateUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new RuntimeException('MCP Hub connection is not configured.');
        }
        $parts = parse_url($url);
        $local = is_array($parts) && app()->environment(['local', 'testing'])
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '::1'], true);
        if (! is_array($parts) || empty($parts['host'])
            || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('MCP Hub connection is not configured.');
        }
        return $local;
    }
}
