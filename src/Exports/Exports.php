<?php

namespace McpHub\Laravel\Exports;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Security\Actor;
use McpHub\Laravel\Support\FieldValue;
use McpHub\Laravel\Support\InputSchema;
use McpHub\Laravel\Support\Json;
use Throwable;

final class Exports
{
    private $registry;
    private $connection;
    private $encrypter;
    private $files;

    public function __construct(Registry $registry, Connection $connection, Encrypter $encrypter, ExportFiles $files)
    {
        $this->registry = $registry;
        $this->connection = $connection;
        $this->encrypter = $encrypter;
        $this->files = $files;
    }

    public static function inputSchema(array $definition): array
    {
        $properties = [
            'format' => ['type' => 'string', 'maxLength' => 4, 'enum' => $definition['exports']],
            'columns' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 512],
            'metric' => ['type' => 'string', 'maxLength' => 8, 'enum' => ['records', 'count', 'sum', 'avg', 'min', 'max']],
            'field' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 63],
            'group_by' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 127],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
        ];
        foreach ($definition['filters'] as $field => $values) {
            $properties[$field] = ['type' => 'string', 'maxLength' => 64, 'enum' => array_values($values)];
        }
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => ['format']];
    }

    public function prepare(Actor $actor, string $resource, array $input, string $key): array
    {
        $definition = $this->authorized($actor, $resource);
        InputSchema::validate(self::inputSchema($definition), $input);
        $this->selection($definition, $input);
        if (! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) {
            throw new ExportException('A valid idempotency key is required.', 422);
        }
        // Never dispatch a worker against an uncommitted metadata row.
        if (DB::connection()->transactionLevel() !== 0) {
            throw new ExportException('Export preparation requires committed metadata.', 409);
        }
        $queueConnection = config('mcp-hub.exports.connection') ?: config('queue.default');
        $driver = config('queue.connections.'.$queueConnection.'.driver');
        if (! is_string($driver) || (app()->environment('production') && $driver === 'sync')) {
            throw new ExportException('An asynchronous queue is required.', 503);
        }
        ksort($input);
        $digest = hash('sha256', Json::encode([$resource, $input]));
        $actorKey = $this->actorKey($actor);
        $requestKey = hash('sha256', $key);
        try {
            list($row, $created) = $this->connection->locked('connection', function () use ($actor, $definition, $resource,
                $input, $digest, $actorKey, $requestKey) {
                $existing = DB::table('mcp_hub_exports')->where('actor_key', $actorKey)->where('request_key', $requestKey)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_digest, $digest)) {
                        throw new ExportException('Idempotency key was already used.', 409);
                    }
                    return [$existing, false];
                }
                $active = DB::table('mcp_hub_exports')->where('expires_at', '>', time())
                    ->whereIn('status', ['pending', 'building', 'ready']);
                if ((clone $active)->where('actor_key', $actorKey)->count() >= 2 || $active->count() >= 40) {
                    throw new ExportException('Export capacity is temporarily exhausted.', 429);
                }
                $row = [
                    'id' => 'exp_'.strtr(bin2hex(random_bytes(24)), '0123456789abcdef', 'abcdefghijklmnop'),
                    'actor_key' => $actorKey, 'request_key' => $requestKey, 'request_digest' => $digest,
                    'resource' => $resource, 'format' => $input['format'], 'policy_digest' => $this->policyDigest($definition),
                    'payload' => $this->encrypt(['actor' => [$actor->reference, $actor->tenant], 'input' => $input]),
                    'status' => 'pending', 'coverage' => 'unknown', 'row_count' => 0, 'created_at' => time(),
                    'expires_at' => time() + max(30, min(900, (int) config('mcp-hub.exports.ttl', 600))),
                ];
                DB::table('mcp_hub_exports')->insert($row);
                return [(object) $row, true];
            });
        } catch (ExportException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new ExportException('Export preparation is temporarily unavailable.', 503);
        }
        if ($created) {
            try {
                app(Dispatcher::class)->dispatch(new BuildExport($row->id));
            } catch (Throwable $error) {
                $this->fail($row->id);
            }
        }
        return $this->status($actor, $row->id, $resource);
    }

    public function status(Actor $actor, string $id, ?string $resource = null): array
    {
        $row = $this->owned($actor, $id);
        $definition = $this->authorized($actor, $row->resource);
        if (($resource !== null && $row->resource !== $resource)
            || ! hash_equals($row->policy_digest, $this->policyDigest($definition))) {
            throw new ExportException('Export unavailable.', 404);
        }
        $expired = $row->expires_at <= time();
        return ['export_ref' => $row->id, 'status' => $expired ? 'expired' : $row->status, 'format' => $row->format,
            'expires_at' => gmdate('c', $row->expires_at), 'coverage' => $row->coverage,
            'row_count' => (int) $row->row_count, 'tenant_id' => $actor->tenant,
            'download_url' => ! $expired && $row->status === 'ready' ? $this->downloadUrl($row->id) : null];
    }

    /** Idempotent worker: every account, resource policy and field grant is checked again. */
    public function build(string $id): void
    {
        if (! preg_match('/^exp_[a-p]{48}$/D', $id)
            || DB::table('mcp_hub_exports')->where('id', $id)->where('status', 'pending')
                ->where('expires_at', '>', time())->update(['status' => 'building']) !== 1) {
            return;
        }
        try {
            $row = DB::table('mcp_hub_exports')->where('id', $id)->first();
            $payload = $this->decrypt($row->payload);
            $actor = app(ActorResolver::class)->resolve($payload['actor'][0], $payload['actor'][1]);
            $definition = $this->authorized($actor, $row->resource);
            if (! hash_equals($row->actor_key, $this->actorKey($actor))
                || ! hash_equals($row->policy_digest, $this->policyDigest($definition))) {
                throw new ExportException('Export policy changed.');
            }
            list($columns, $report, $snapshot, $coverage) = $this->report($actor, $row->resource, $definition, $payload['input']);
            $bytes = app(ReportWriter::class)->render($row->format, $columns, $report, $coverage, gmdate('c', $row->created_at));
            $payload['snapshot'] = $snapshot;
            $payload['file_digest'] = hash('sha256', $bytes);
            $this->files->write($id, $bytes);
            if (DB::table('mcp_hub_exports')->where('id', $id)->where('status', 'building')->where('expires_at', '>', time())
                ->update(['status' => 'ready', 'payload' => $this->encrypt($payload),
                    'coverage' => $coverage, 'row_count' => count($report)]) !== 1) {
                $this->files->delete($id);
            }
        } catch (Throwable $error) {
            // No record values, SQL bindings, assertions or exception chains enter queue logs.
            $this->fail($id);
        }
    }

    public function download(Actor $actor, string $id): array
    {
        $row = $this->owned($actor, $id);
        if ($row->expires_at <= time()) {
            $this->files->delete($id);
            throw new ExportException('Export expired.', 410);
        }
        $definition = $this->authorized($actor, $row->resource);
        if ($row->status !== 'ready' || ! hash_equals($row->policy_digest, $this->policyDigest($definition))) {
            throw new ExportException('Export unavailable.', 409);
        }
        $payload = $this->decrypt($row->payload);
        // Re-run the bounded, current policy-scoped query. A changed, moved, deleted or newly
        // visible row invalidates this snapshot; stale aggregates are never downloadable.
        list($columns, $report, $snapshot) = $this->report($actor, $row->resource, $definition, $payload['input']);
        if (! hash_equals(hash('sha256', Json::encode($payload['snapshot'])), hash('sha256', Json::encode($snapshot)))) {
            throw new ExportException('Export data or record access changed. Request a new export.', 409);
        }
        $bytes = $this->files->read($id);
        if (! hash_equals($payload['file_digest'], hash('sha256', $bytes))) {
            throw new ExportException('Export unavailable.', 409);
        }
        if ($row->expires_at <= time()) {
            throw new ExportException('Export expired.', 410);
        }
        return [$row->format, $bytes];
    }

    public function prune(): void
    {
        DB::table('mcp_hub_exports')->where('expires_at', '<=', time())->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $this->files->delete($row->id);
                DB::table('mcp_hub_exports')->where('id', $row->id)->update(['status' => 'expired', 'payload' => '']);
            }
        });
        DB::table('mcp_hub_exports')->where('expires_at', '<', time() - 86400)->delete();
    }

    private function authorized(Actor $actor, string $resource): array
    {
        $definition = $this->registry->resources()[$resource] ?? null;
        if (! $this->connection->get('enabled') || config('mcp-hub.exports.enabled') !== true || ! $definition
            || ! $definition['exports'] || ! app($definition['policy'])->allows($actor, $resource, 'export')
            || ! app($definition['policy'])->allows($actor, $resource, 'list')) {
            throw new ExportException('Export is not permitted.');
        }
        return $definition;
    }

    private function selection(array $definition, array $input): array
    {
        $metric = $input['metric'] ?? 'records';
        $columns = isset($input['columns']) ? explode(',', $input['columns']) : array_slice(array_keys($definition['fields']), 0, 10);
        $group = isset($input['group_by']) ? explode(',', $input['group_by']) : [];
        if (count($columns) > 10 || count($columns) !== count(array_unique($columns))
            || count($group) > 2 || count($group) !== count(array_unique($group))
            || array_diff(array_merge($columns, $group), array_keys($definition['fields']))
            || ($metric === 'records' && (isset($input['field']) || $group))
            || ($metric !== 'records' && isset($input['columns']))
            || ($metric === 'count' && isset($input['field']))
            || in_array($metric, $group, true)
            || (in_array($metric, ['sum', 'avg', 'min', 'max'], true)
                && ! in_array($definition['fields'][$input['field'] ?? ''] ?? null, ['integer', 'number'], true))) {
            throw new ExportException('Report columns or metric are invalid.', 422);
        }
        $selected = $metric === 'records' ? $columns : array_merge($group, isset($input['field']) ? [$input['field']] : []);
        if ($input['format'] === 'pdf' && ($input['limit'] ?? 100) > 100) {
            throw new ExportException('PDF reports require a limit of at most 100.', 422);
        }
        return [$metric, $columns, $group, array_values(array_unique($selected))];
    }

    private function report(Actor $actor, string $resource, array $definition, array $input): array
    {
        InputSchema::validate(self::inputSchema($definition), $input);
        list($metric, $columns, $groups, $selected) = $this->selection($definition, $input);
        $query = app($definition['policy'])->scope($definition['model']::query(), $actor);
        foreach ($definition['filters'] as $field => $values) {
            if (isset($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        $primary = $query->getModel()->getKeyName();
        $version = $query->getModel()->usesTimestamps() ? $query->getModel()->getUpdatedAtColumn() : null;
        $limit = $input['limit'] ?? ($input['format'] === 'pdf' ? 100 : 200);
        $models = $query->select(array_values(array_unique(array_merge([$primary], $version ? [$version] : [], $selected))))
            ->orderBy($primary)->limit($limit + 1)->get();
        $coverage = count($models) > $limit ? 'partial' : 'complete';
        $rows = [];
        $snapshot = [];
        $buckets = [];
        foreach ($models->take($limit) as $model) {
            $values = [];
            foreach ($selected as $field) {
                $values[$field] = FieldValue::present($model->getAttribute($field), $definition['fields'][$field]);
                if (is_string($values[$field]) && (! preg_match('//u', $values[$field])
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $values[$field]))) {
                    throw new ExportException('Report value is invalid.');
                }
            }
            $snapshot[] = $this->mac(Json::encode([$model->getKey(), $values, $version ? ($model->getAttributes()[$version] ?? null) : null]));
            if ($metric === 'records') {
                $rows[] = $values;
                continue;
            }
            $group = array_intersect_key($values, array_flip($groups));
            $bucketKey = Json::encode($group);
            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = ['group' => $group, 'count' => 0, 'sum' => 0, 'min' => null, 'max' => null, 'values' => 0];
            }
            $bucket = &$buckets[$bucketKey];
            ++$bucket['count'];
            $value = $values[$input['field'] ?? ''] ?? null;
            if ($value !== null) {
                ++$bucket['values'];
                $bucket['sum'] += $value;
                $bucket['min'] = $bucket['min'] === null ? $value : min($bucket['min'], $value);
                $bucket['max'] = $bucket['max'] === null ? $value : max($bucket['max'], $value);
                if (! is_finite((float) $bucket['sum'])) {
                    throw new ExportException('Report total exceeds the numeric range.');
                }
            }
            unset($bucket);
        }
        // Coverage is bound into the snapshot, including a change at the limit boundary.
        $snapshot[] = $coverage;
        if ($metric !== 'records') {
            if (! $buckets && ! $groups) {
                $buckets['{}'] = ['group' => [], 'count' => 0, 'sum' => 0, 'values' => 0, 'min' => null, 'max' => null];
            }
            foreach ($buckets as $bucket) {
                $value = $metric === 'avg' ? ($bucket['values'] ? $bucket['sum'] / $bucket['values'] : null) :
                    ($metric === 'sum' && ! $bucket['values'] ? null : $bucket[$metric]);
                $rows[] = $bucket['group'] + [$metric => $value];
            }
            $columns = array_intersect_key($definition['fields'], array_flip($groups));
            // Preserve the selected grouping order in both header and data.
            $columns = array_replace(array_fill_keys($groups, 'string'), $columns);
            $columns[$metric] = $metric === 'count' ? 'integer' : 'number';
        } else {
            $columns = array_replace(array_fill_keys($columns, 'string'), array_intersect_key($definition['fields'], array_flip($columns)));
        }
        return [$columns, $rows, $snapshot, $coverage];
    }

    private function owned(Actor $actor, string $id)
    {
        if (! preg_match('/^exp_[a-p]{48}$/D', $id)) {
            throw new ExportException('Export unavailable.', 404);
        }
        $row = DB::table('mcp_hub_exports')->where('id', $id)->where('actor_key', $this->actorKey($actor))->first();
        if (! $row) {
            throw new ExportException('Export unavailable.', 404);
        }
        return $row;
    }

    private function actorKey(Actor $actor): string
    {
        return $this->mac(Json::encode([$this->connection->get('app_key'), $this->connection->get('environment'),
            $this->connection->get('source_url'), $actor->reference, $actor->tenant]));
    }

    private function policyDigest(array $definition): string
    {
        return hash('sha256', Json::encode([$definition['model'], $definition['policy'], $definition['fields'],
            $definition['filters'], $definition['exports']]));
    }

    private function mac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->encrypter->getKey());
    }

    private function encrypt(array $payload): string
    {
        $json = Json::encode($payload);
        if (strlen($json) > 131072) {
            throw new ExportException('Export metadata is too large.', 413);
        }
        return $this->encrypter->encryptString($json);
    }

    private function decrypt(string $payload): array
    {
        return Json::decode($this->encrypter->decryptString($payload), 16);
    }

    private function downloadUrl(string $id): string
    {
        // APP_URL is trusted operator configuration; never use an incoming Host header.
        $base = rtrim((string) $this->connection->get('source_url'), '/');
        $url = parse_url($base);
        if (! $url || ! in_array($url['scheme'] ?? '', ['https', 'http'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || strlen($base) > 1500) {
            throw new ExportException('Export download URL is not configured.', 503);
        }
        $download = $base.'/'.trim(config('mcp-hub.web.prefix'), '/').'/exports/'.$id;
        if (strlen($download) > 512) {
            throw new ExportException('Export download URL is too long.', 503);
        }
        return $download;
    }

    private function fail(string $id): void
    {
        $this->files->delete($id);
        DB::table('mcp_hub_exports')->where('id', $id)->whereIn('status', ['pending', 'building', 'ready'])
            ->update(['status' => 'failed', 'payload' => '']);
    }
}
