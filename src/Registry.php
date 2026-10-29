<?php

namespace McpHub\Laravel;

use Illuminate\Database\Eloquent\Model;
use McpHub\Laravel\Contracts\ResourcePolicy;
use McpHub\Laravel\Contracts\CommandHandler;
use InvalidArgumentException;
use McpHub\Laravel\Support\Json;
use McpHub\Laravel\Support\InputSchema;
use McpHub\Laravel\Security\FieldPrivacy;

class Registry
{
    public function resources(): array
    {
        return $this->collect(true);
    }

    /** Developer-approved capabilities before optional administrator restrictions. */
    public function declaredResources(): array
    {
        return $this->collect(false);
    }

    private function collect(bool $applyGrants): array
    {
        $out = [];
        $definitions = config('mcp-hub.models', []);
        $files = config('mcp-hub-resources', []);
        $grants = $applyGrants ? app(Connection::class)->get('resource_grants', []) : [];
        if (! is_array($definitions) || ! is_array($files) || ! is_array($grants)
            || array_intersect_key($definitions, $files) || count($definitions) + count($files) > 100) {
            throw new InvalidArgumentException('Resource registry is invalid.');
        }
        $definitions = array_merge($definitions, $files);
        foreach ($definitions as $key => $definition) {
            if (! is_array($definition)) {
                throw new InvalidArgumentException('Resource definition is invalid.');
            }
            if (($definition['enabled'] ?? false) !== true || ($definition['read'] ?? false) !== true) {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $key)
                || ! is_subclass_of($definition['model'] ?? '', Model::class)
                || ! is_subclass_of($definition['policy'] ?? '', ResourcePolicy::class)) {
                throw new InvalidArgumentException('Invalid resource registration.');
            }
            $model = new $definition['model'];
            $fields = $definition['fields'] ?? [];
            if (! is_array($fields) || $fields === [] || count($fields) > 50) {
                throw new InvalidArgumentException('Explicit resource fields are required.');
            }
            foreach ($fields as $field => $type) {
                if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/D', $field)
                    || FieldPrivacy::blocked($field)
                    || substr($field, -3) === '_id' || in_array($field, ['id', 'ref', 'tenant_id', 'count'], true)
                    || in_array($field, $model->getHidden(), true)
                    || ! in_array($type, ['string', 'integer', 'number', 'boolean', 'datetime'], true)) {
                    throw new InvalidArgumentException('Resource field is not permitted.');
                }
            }
            $lookup = $definition['lookup'] ?? null;
            if ($lookup !== null && (in_array($lookup, ['limit', 'cursor'], true) || ($fields[$lookup] ?? null) !== 'string')) {
                throw new InvalidArgumentException('Lookup requires a readable string field.');
            }
            foreach ($definition['filters'] ?? [] as $field => $values) {
                if (in_array($field, ['limit', 'cursor'], true) || ($fields[$field] ?? null) !== 'string'
                    || ! is_array($values) || $values === [] || count($values) > 20) {
                    throw new InvalidArgumentException('Invalid resource filter.');
                }
                foreach ($values as $value) {
                    if (! is_string($value) || $value === '' || strlen($value) > 64 || preg_match('/\p{C}/u', $value)) {
                        throw new InvalidArgumentException('Invalid resource filter value.');
                    }
                }
            }
            $writeFields = $definition['writable_fields'] ?? [];
            $commands = $definition['commands'] ?? [];
            if (! is_array($writeFields) || count($writeFields) > 50 || ! is_array($commands)
                || array_diff(array_keys($commands), ['create', 'update', 'delete'])) {
                throw new InvalidArgumentException('Invalid command registration.');
            }
            foreach ($writeFields as $field) {
                if (! is_string($field) || ! self::writableField($field, $model)) {
                    throw new InvalidArgumentException('Command field is not permitted.');
                }
            }
            foreach ($commands as $operation => $command) {
                if (! is_array($command) || ! is_subclass_of($command['handler'] ?? '', CommandHandler::class)
                    || ! is_array($command['input_schema'] ?? null)
                    || array_diff(array_keys($command), ['handler', 'input_schema'])) {
                    throw new InvalidArgumentException('Invalid command handler.');
                }
                InputSchema::definition($command['input_schema']);
                foreach (array_keys($command['input_schema']['properties']) as $field) {
                    if ($field !== 'ref' && ! in_array($field, $writeFields, true)) {
                        throw new InvalidArgumentException('Command inputs exceed the writable field allowlist.');
                    }
                }
            }
            $definition['commands'] = $commands;
            $definition['writable_fields'] = $writeFields;
            $exports = $definition['exports'] ?? [];
            if (! is_array($exports) || array_filter($exports, function ($format) { return ! is_string($format); })
                || count($exports) !== count(array_unique($exports))
                || array_diff($exports, ['csv', 'xlsx', 'pdf'])
                || ($exports && (count($definition['filters'] ?? []) > 24
                    || array_intersect(array_keys($definition['filters'] ?? []), ['format', 'columns', 'metric', 'field', 'group_by', 'limit'])))) {
                throw new InvalidArgumentException('Invalid export registration.');
            }
            $definition['exports'] = array_values($exports);
            $grant = $grants[$key] ?? null;
            if ($grant !== null) {
                if (! is_array($grant) || ! is_bool($grant['enabled'] ?? null)
                    || ! is_array($grant['fields'] ?? null) || ! is_array($grant['operations'] ?? null)
                    || array_diff($grant['fields'], array_keys($fields))
                    || array_diff($grant['operations'], array_keys($commands))) {
                    throw new InvalidArgumentException('Stored resource permissions are invalid.');
                }
                if (! $grant['enabled'] || $grant['fields'] === []) {
                    continue;
                }
                $definition['fields'] = array_intersect_key($fields, array_flip($grant['fields']));
                $definition['lookup'] = in_array($lookup, $grant['fields'], true) ? $lookup : null;
                $definition['filters'] = array_intersect_key($definition['filters'] ?? [], $definition['fields']);
                $definition['commands'] = array_intersect_key($commands, array_flip($grant['operations']));
            }
            $out[$key] = $definition + ['lookup' => null, 'filters' => []];
        }
        return $out;
    }

    public function manifest(Connection $connection): array
    {
        $appKey = (string) $connection->get('app_key');
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $appKey)) {
            throw new InvalidArgumentException('Invalid application key.');
        }
        $modules = [];
        foreach ($this->resources() as $key => $resource) {
            $outputs = ['ref' => ['type' => 'string', 'maxLength' => 1024]];
            foreach ($resource['fields'] as $field => $type) {
                $outputs[$field] = in_array($type, ['string', 'datetime'], true)
                    ? ['type' => ['string', 'null'], 'maxLength' => 512] : ['type' => [$type, 'null']];
            }
            $routes = [];
            foreach (array_merge(['list', 'count'], $resource['lookup'] ? ['get'] : []) as $action) {
                $input = [];
                if ($action === 'list') {
                    $input = ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                        'cursor' => ['type' => 'string', 'maxLength' => 20]];
                }
                foreach ($resource['filters'] as $field => $values) {
                    $input[$field] = ['type' => 'string', 'maxLength' => 64, 'enum' => array_values($values)];
                }
                if ($action === 'get') {
                    $input[$resource['lookup']] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 200];
                }
                $inputSchema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $input];
                if ($action === 'get') {
                    $inputSchema['required'] = [$resource['lookup']];
                }
                InputSchema::definition($inputSchema);
                $inputSchema['properties'] = (object) $input;
                $properties = $action === 'count' ? ['count' => ['type' => 'integer']] : $outputs;
                $routes[] = ['tool_key' => $appKey.'.'.$key.'.'.$action,
                    'description' => 'Read permitted '.$key.' records.',
                    'method' => 'GET', 'path_template' => '/'.trim(config('mcp-hub.routes.prefix'), '/').'/'.$key.'/'.$action,
                    'requires_user_context' => true, 'tenant_field' => 'tenant_id', 'input_schema' => $inputSchema,
                    'output_schema' => ['type' => 'array', 'maxItems' => $action === 'list' ? 50 : 1,
                        'items' => ['type' => 'object', 'additionalProperties' => false,
                            'properties' => $properties, 'required' => array_keys($properties)]]];
            }
            $writes = [];
            $readable = array_merge(array_keys($outputs), ['count']);
            if (config('mcp-hub.exports.enabled') === true && $resource['exports']) {
                $properties = [
                    'export_ref' => ['type' => 'string', 'maxLength' => 52],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'building', 'ready', 'failed', 'expired']],
                    'format' => ['type' => 'string', 'enum' => $resource['exports']],
                    'expires_at' => ['type' => 'string', 'maxLength' => 32],
                    'coverage' => ['type' => 'string', 'enum' => ['unknown', 'partial', 'complete']],
                    'row_count' => ['type' => 'integer'],
                    'download_url' => ['type' => ['string', 'null'], 'maxLength' => 512],
                ];
                $schema = ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'object',
                    'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)]];
                $input = \McpHub\Laravel\Exports\Exports::inputSchema($resource);
                InputSchema::definition($input);
                $input['properties'] = (object) $input['properties'];
                $base = '/'.trim(config('mcp-hub.routes.prefix'), '/').'/'.$key.'/exports';
                $routes[] = ['tool_key' => $appKey.'.'.$key.'.export',
                    'description' => 'Prepare a private, expiring report download in the source application. Check export_status until ready. Partial coverage is limited, not a full total.',
                    'method' => 'POST', 'safe_query' => true, 'path_template' => $base, 'requires_user_context' => true,
                    'tenant_field' => 'tenant_id', 'input_schema' => $input, 'output_schema' => $schema];
                $routes[] = ['tool_key' => $appKey.'.'.$key.'.export_status',
                    'description' => 'Check a report export. Download requires signing in to the source application before expiry.',
                    'method' => 'GET', 'path_template' => $base.'/{export_ref}', 'requires_user_context' => true,
                    'tenant_field' => 'tenant_id', 'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                        'properties' => ['export_ref' => ['type' => 'string', 'minLength' => 52, 'maxLength' => 52]],
                        'required' => ['export_ref']], 'output_schema' => $schema];
                $readable = array_values(array_unique(array_merge($readable, array_keys($properties))));
            }
            if (config('mcp-hub.commands.enabled') && $resource['commands']) {
                $receiptProperties = [
                    'proposal_ref' => ['type' => 'string', 'maxLength' => 80],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'committed', 'cancelled', 'expired']],
                    'operation' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                    'requires_confirmation' => ['type' => 'boolean'],
                    'expires_at' => ['type' => 'string', 'maxLength' => 32],
                ];
                $receiptSchema = ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'object',
                    'additionalProperties' => false, 'properties' => $receiptProperties,
                    'required' => array_keys($receiptProperties)]];
                foreach ($resource['commands'] as $operation => $command) {
                    $schema = $command['input_schema'];
                    $schema['properties'] = (object) $schema['properties'];
                    $writes[] = ['tool_key' => $appKey.'.'.$key.'.'.$operation,
                        'description' => 'Prepare a '.$operation.' proposal for '.$key.'. Requires application confirmation.',
                        'operation' => $operation, 'workflow' => 'proposal', 'method' => 'POST',
                        'path_template' => '/'.trim(config('mcp-hub.routes.prefix'), '/').'/'.$key.'/commands/'.$operation,
                        'requires_user_context' => true, 'tenant_field' => 'tenant_id',
                        'input_schema' => $schema, 'output_schema' => $receiptSchema];
                }
                $routes[] = ['tool_key' => $appKey.'.'.$key.'.command_status',
                    'description' => 'Check a proposal status. Only committed means the change succeeded.',
                    'method' => 'GET', 'path_template' => '/'.trim(config('mcp-hub.routes.prefix'), '/').'/'.$key.'/commands/{proposal_ref}',
                    'requires_user_context' => true, 'tenant_field' => 'tenant_id',
                    'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                        'properties' => ['proposal_ref' => ['type' => 'string', 'maxLength' => 80]],
                        'required' => ['proposal_ref']], 'output_schema' => $receiptSchema];
                $readable = array_values(array_unique(array_merge($readable, array_keys($receiptProperties))));
            }
            foreach (array_merge($routes, $writes) as $endpoint) {
                if (strlen($endpoint['tool_key']) > 80) {
                    throw new InvalidArgumentException('Combined application, resource and action keys exceed the Hub limit. Use shorter registered keys.');
                }
            }
            $module = ['key' => $key, 'name' => $resource['label'] ?? $key,
                'schema_version' => '1.0', 'supported_operations' => array_merge(['read'], array_keys($writes ? $resource['commands'] : [])),
                'readable_fields' => $readable, 'writable_fields' => $writes ? array_values($resource['writable_fields']) : [],
                'field_policies' => (object) [], 'max_page_size' => 50, 'read_endpoints' => $routes];
            if ($writes) {
                $module['write_endpoints'] = $writes;
            }
            $modules[] = $module;
        }
        return ['contract_version' => '1.0', 'application' => $appKey, 'managed_by' => 'application',
            'manifest_version' => substr(hash('sha256', Json::encode($modules)), 0, 32),
            'modules' => $modules];
    }

    private static function writableField(string $field, Model $model): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/D', $field)
            && ! FieldPrivacy::blocked($field)
            && substr($field, -3) !== '_id' && ! in_array($field, ['id', 'ref', 'tenant_id', 'count', 'role', 'permissions'], true)
            && ! in_array($field, $model->getHidden(), true);
    }
}
