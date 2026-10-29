<?php

namespace McpHub\Laravel;

use McpHub\Laravel\Support\Json;
use RuntimeException;

final class Pairing
{
    private $connection;
    private $transport;

    public function __construct(Connection $connection, HubTransport $transport)
    {
        $this->connection = $connection;
        $this->transport = $transport;
    }

    public function connect(string $hub, string $code, bool $dryRun = false, bool $replace = false): array
    {
        $hub = $this->base($hub);
        if (! preg_match('/^enr_([a-f0-9-]{36})_[A-Za-z0-9_-]{32,43}$/D', $code, $match)) {
            throw new RuntimeException('Pairing code is invalid.');
        }
        if ($dryRun) {
            return ['status' => 'ready', 'changed' => false];
        }
        return $this->connection->locked('pairing', function () use ($hub, $code, $match, $replace) {
            $stored = $this->connection->stored();
            if (! $replace && (! empty($stored['client_key']) || ! empty($stored['pairing']))) {
                throw new RuntimeException('An existing connection must be resumed or explicitly disconnected.');
            }
            $previous = $stored['pairing'] ?? null;
            if ($replace && (! is_array($previous) || ($previous['hub'] ?? null) !== $hub || ($previous['id'] ?? null) === $match[1])) {
                throw new RuntimeException('Use a replacement code for the existing Hub connection.');
            }
            $state = ['hub' => $hub, 'id' => $match[1], 'installation_id' => bin2hex(random_bytes(16)),
                'attempt_id' => bin2hex(random_bytes(16)), 'proof' => bin2hex(random_bytes(32)), 'status' => 'claiming'];
            if ($replace) {
                $state['expected'] = array_intersect_key($stored, array_flip(['app_key', 'environment', 'url', 'source_url', 'connector_id']));
                $state['installation_id'] = $previous['installation_id'];
            }
            // Save recovery proof and handle before consuming the one-use code.
            $this->connection->update(['pairing' => $state]);
            $claimed = $this->request($hub.'/api/connect/v1/enrollments/claim', $this->proof($state) + ['code' => $code]);
            if (($claimed['id'] ?? null) !== $state['id'] || ($claimed['status'] ?? null) !== 'claimed') {
                throw new RuntimeException('Enrollment binding is invalid.');
            }
            $bundle = $this->validateBundle($claimed['connection'] ?? [], $hub, $claimed);
            foreach ($state['expected'] ?? [] as $key => $value) {
                if ($bundle[$key] !== $value) {
                    throw new RuntimeException('Replacement changed the installation binding.');
                }
            }
            $state['status'] = 'configured';
            $this->connection->update($bundle + ['pairing' => $state]);
            return $this->complete($state);
        });
    }

    public function resume(): array
    {
        return $this->connection->locked('pairing', function () {
            $state = $this->state();
            $status = $this->request($this->endpoint($state, 'status'), $this->proof($state));
            $stored = $this->connection->stored();
            if ($state['status'] === 'claiming' || empty($stored['client_key']) || empty($stored['app_token']) || empty($stored['delegation_secret'])) {
                // A lost claim response cannot be recovered by reading stored Hub secrets.
                throw new RuntimeException('The credential bundle was not saved. Revoke this enrollment and create a replacement.');
            }
            if (($status['status'] ?? null) === 'active') {
                $state['status'] = 'active';
                $this->connection->update(['pairing' => $state]);
                return $this->safeStatus($state);
            }
            return $this->complete($state);
        });
    }

    public function sync(): array
    {
        return $this->connection->locked('pairing', function () {
            $state = $this->state();
            $status = $this->request($this->endpoint($state, 'sync'), $this->proof($state));
            if (($status['status'] ?? null) !== 'active' || ($status['id'] ?? null) !== $state['id']) {
                throw new RuntimeException('Connector is not active.');
            }
            return $this->safeStatus($state);
        });
    }

    public function disconnect(): array
    {
        return $this->connection->locked('pairing', function () {
            // Close local access even if the remote Hub cannot be reached.
            $this->connection->update(['enabled' => false]);
            try {
                $state = $this->state();
            } catch (RuntimeException $error) {
                return ['status' => 'manual_revocation_required'];
            }
            $state['status'] = 'revocation_pending';
            $this->connection->update(['pairing' => $state]);
            try {
                $status = $this->request($this->endpoint($state, 'disconnect'), $this->proof($state));
                if (($status['id'] ?? null) !== $state['id'] || ($status['status'] ?? null) !== 'revoked') {
                    throw new RuntimeException();
                }
                $state['status'] = 'revoked';
            } catch (HubException $error) {
                if ($error->status() === 410) {
                    $state['status'] = 'revoked';
                }
            } catch (\Throwable $error) {
                // Retain the encrypted proof for a later revocation retry.
            }
            $this->connection->update(['pairing' => $state]);
            return $this->safeStatus($state);
        });
    }

    public function status(): array
    {
        $stored = $this->connection->stored();
        $state = $stored['pairing'] ?? null;
        return is_array($state) ? $this->safeStatus($state) : ['status' => 'not_paired'];
    }

    private function complete(array $state): array
    {
        $status = $this->request($this->endpoint($state, 'complete'), $this->proof($state));
        if (($status['status'] ?? null) !== 'active' || ($status['id'] ?? null) !== $state['id']) {
            throw new RuntimeException('Enrollment is not active.');
        }
        $state['status'] = 'active';
        $this->connection->update(['pairing' => $state]);
        return $this->safeStatus($state);
    }

    private function validateBundle(array $bundle, string $hub, array $claimed): array
    {
        $keys = ['app_key', 'environment', 'url', 'source_url', 'app_token', 'delegation_secret', 'client_key', 'connector_id'];
        foreach ($keys as $key) {
            if (! is_string($bundle[$key] ?? null) || $bundle[$key] === '' || strlen($bundle[$key]) > 2048) {
                throw new RuntimeException('Enrollment bundle is invalid.');
            }
            $declared = config('mcp-hub.'.$key);
            if ($declared !== null && $declared !== '' && rtrim((string) $declared, '/') !== rtrim($bundle[$key], '/')) {
                throw new RuntimeException('Existing application configuration conflicts with the enrollment.');
            }
        }
        if ($bundle['url'] !== $hub.'/mcp' || ($claimed['source_url'] ?? null) !== $bundle['source_url']
            || ($claimed['connector_id'] ?? null) !== $bundle['connector_id']
            || rtrim((string) $this->connection->get('source_url'), '/') !== rtrim($bundle['source_url'], '/')
            || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $bundle['app_key'])
            || ! in_array($bundle['environment'], ['dev', 'test', 'staging', 'production'], true)
            || strlen($bundle['app_token']) < 32 || strlen($bundle['delegation_secret']) < 32
            || strlen($bundle['client_key']) < 32 || config('mcp-hub.enabled') === false) {
            throw new RuntimeException('Enrollment bundle does not match this installation.');
        }
        return array_intersect_key($bundle, array_flip($keys));
    }

    private function base(string $hub): string
    {
        $hub = rtrim($hub, '/');
        $hub = substr($hub, -4) === '/mcp' ? substr($hub, 0, -4) : $hub;
        $this->transport->validateUrl($hub);
        return $hub;
    }

    private function state(): array
    {
        $state = $this->connection->stored()['pairing'] ?? null;
        if (! is_array($state) || empty($state['hub']) || empty($state['id'])
            || empty($state['proof']) || empty($state['installation_id']) || empty($state['attempt_id'])
            || ! preg_match('/^[a-f0-9-]{36}$/D', $state['id'])) {
            throw new RuntimeException('No enrollment is available to resume.');
        }
        $state['hub'] = $this->base($state['hub']);
        return $state;
    }

    private function endpoint(array $state, string $operation): string
    {
        return $state['hub'].'/api/connect/v1/enrollments/'.$state['id'].'/'.$operation;
    }

    private function proof(array $state): array
    {
        return array_intersect_key($state, array_flip(['installation_id', 'attempt_id', 'proof']));
    }

    private function request(string $url, array $body): array
    {
        $result = Json::decode($this->transport->exchange('POST', $url, $body), 32);
        if (! is_array($result) || ($result['success'] ?? null) !== true || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('Enrollment request failed.');
        }
        return $result['data'];
    }

    private function safeStatus(array $state): array
    {
        return ['status' => $state['status'], 'enrollment_id' => $state['id']];
    }
}
