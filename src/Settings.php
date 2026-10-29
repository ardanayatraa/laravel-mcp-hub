<?php

namespace McpHub\Laravel;

use InvalidArgumentException;
use McpHub\Laravel\Commands\CommandException;
use McpHub\Laravel\Support\Json;

/** Administrator choices only restrict the developer's registry. */
final class Settings
{
    private $connection;
    private $registry;
    private $pairing;

    public function __construct(Connection $connection, Registry $registry, Pairing $pairing)
    {
        $this->connection = $connection;
        $this->registry = $registry;
        $this->pairing = $pairing;
    }

    public function snapshot(): array
    {
        $declared = $this->registry->declaredResources();
        $grants = $this->connection->get('resource_grants', []);
        if (! is_array($grants)) {
            throw new InvalidArgumentException('Invalid permission store.');
        }
        $resources = [];
        foreach ($declared as $key => $definition) {
            $fields = array_keys($definition['fields']);
            $operations = config('mcp-hub.commands.enabled') ? array_keys($definition['commands']) : [];
            $grant = $grants[$key] ?? ['enabled' => true, 'fields' => $fields, 'operations' => $operations];
            $resources[$key] = [
                'label' => is_string($definition['label'] ?? null) ? $definition['label'] : $key,
                'fields' => $fields, 'operations' => $operations,
                'grant' => ['enabled' => $grant['enabled'] === true,
                    'fields' => array_values(array_intersect($fields, $grant['fields'] ?? [])),
                    'operations' => array_values(array_intersect($operations, $grant['operations'] ?? []))],
            ];
        }
        $enabled = (bool) $this->connection->get('enabled');
        $configured = true;
        foreach (['url', 'app_key', 'client_key', 'app_token', 'delegation_secret', 'source_url'] as $name) {
            $configured = $configured && is_string($this->connection->get($name)) && $this->connection->get($name) !== '';
        }
        return ['enabled' => $enabled, 'configured' => $configured,
            'hub' => $this->displayUrl((string) $this->connection->get('url')),
            'source' => $this->displayUrl((string) $this->connection->get('source_url')),
            'pairing' => $this->pairing->status(), 'resources' => $resources,
            'permissions_editable' => get_class($this->registry) === Registry::class,
            'revision' => hash('sha256', Json::encode([$declared, $grants, $enabled, (bool) config('mcp-hub.commands.enabled')]))];
    }

    public function save(array $input): array
    {
        return $this->connection->locked('connection', function () use ($input) {
            $current = $this->snapshot();
            if (array_diff(array_keys($input), ['revision', 'enabled', 'resources'])
                || ! is_string($input['revision'] ?? null) || ! is_bool($input['enabled'] ?? null)
                || ! is_array($input['resources'] ?? null)) {
                throw new InvalidArgumentException('Settings request is invalid.');
            }
            if (! hash_equals($current['revision'], $input['revision'])) {
                throw new CommandException('Settings changed. Refresh before saving.');
            }
            if ($input['enabled'] && config('mcp-hub.enabled') === false) {
                throw new CommandException('Settings are managed by the application.', 403);
            }
            if (! $current['permissions_editable']) {
                if ($input['resources'] !== []) {
                    throw new CommandException('Resource permissions are managed by the application.', 403);
                }
                $this->connection->save(array_replace($this->connection->stored(), ['enabled' => $input['enabled']]));
                return $this->snapshot();
            }
            if (array_diff(array_keys($input['resources']), array_keys($current['resources']))
                || array_diff(array_keys($current['resources']), array_keys($input['resources']))) {
                throw new InvalidArgumentException('Resource selection is incomplete.');
            }
            $grants = [];
            foreach ($input['resources'] as $key => $grant) {
                $resource = $current['resources'][$key];
                if (! is_array($grant) || array_diff(array_keys($grant), ['enabled', 'fields', 'operations'])
                    || ! is_bool($grant['enabled'] ?? null) || ! is_array($grant['fields'] ?? null)
                    || ! is_array($grant['operations'] ?? null)
                    || count(array_filter($grant['fields'], 'is_string')) !== count($grant['fields'])
                    || count(array_filter($grant['operations'], 'is_string')) !== count($grant['operations'])
                    || count(array_unique($grant['fields'])) !== count($grant['fields'])
                    || count(array_unique($grant['operations'])) !== count($grant['operations'])
                    || array_diff($grant['fields'], $resource['fields']) || array_diff($grant['operations'], $resource['operations'])
                    || ($grant['enabled'] && $grant['fields'] === [])) {
                    throw new InvalidArgumentException('Resource permissions exceed the developer limits.');
                }
                $grants[$key] = ['enabled' => $grant['enabled'], 'fields' => array_values($grant['fields']),
                    'operations' => array_values($grant['operations'])];
            }
            $this->connection->save(array_replace($this->connection->stored(), [
                'enabled' => $input['enabled'], 'resource_grants' => $grants,
            ]));
            return $this->snapshot();
        });
    }

    private function displayUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '(invalid URL)';
        }
        return $url;
    }
}
