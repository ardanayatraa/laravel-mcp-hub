<?php

namespace McpHub\Laravel\Commands;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Security\Actor;
use McpHub\Laravel\Support\InputSchema;
use McpHub\Laravel\Support\Json;

final class Commands
{
    private $registry;
    private $connection;
    private $encrypter;

    public function __construct(Registry $registry, Connection $connection, Encrypter $encrypter)
    {
        $this->registry = $registry;
        $this->connection = $connection;
        $this->encrypter = $encrypter;
    }

    public function prepare(Actor $actor, string $resource, string $operation, array $input, string $key = ''): array
    {
        list($definition, $command) = $this->authorized($actor, $resource, $operation);
        InputSchema::validate($command['input_schema'], $input);
        \McpHub\Laravel\Security\Privacy::assertSafe($input);
        if ($key !== '' && ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key)) {
            throw new CommandException('Invalid idempotency key.', 422);
        }
        ksort($input);
        $actorKey = $this->actorKey($actor);
        $requestKey = hash('sha256', $key === '' ? bin2hex(random_bytes(32)) : $key);
        $digest = hash('sha256', Json::encode([$resource, $operation, $input]));
        $existing = DB::table('mcp_hub_commands')->where('actor_key', $actorKey)->where('request_key', $requestKey)->first();
        if ($existing) {
            return $this->sameRequest($existing, $digest, $actor);
        }
        // The handler is application-owned and must not mutate records in prepare.
        $prepared = app($command['handler'])->prepare($actor, $operation, $input);
        $visible = InputSchema::validate($command['input_schema'], $prepared->input());
        \McpHub\Laravel\Security\Privacy::assertSafe($visible);
        if (($input['ref'] ?? null) !== ($visible['ref'] ?? null)) {
            throw new CommandException('Prepared target changed.', 422);
        }
        $before = [];
        foreach ($prepared->before() as $field => $value) {
            if (! is_string($field) || ! isset($definition['fields'][$field])) {
                throw new CommandException('Proposal preview exceeds the reviewed fields.', 422);
            }
            $before[$field] = \McpHub\Laravel\Support\FieldValue::present($value, $definition['fields'][$field]);
        }
        if ($operation !== 'create' && $before === []) {
            throw new CommandException('The target record must have a readable preview.', 422);
        }
        $payload = Json::encode(['input' => $visible, 'state' => $prepared->state(), 'before' => $before]);
        if (strlen($payload) > 32768) {
            throw new CommandException('Prepared command is too large.', 413);
        }
        $row = [
            'id' => 'prop_'.strtr(bin2hex(random_bytes(24)), '0123456789abcdef', 'abcdefghijklmnop'), 'actor_key' => $actorKey,
            'request_key' => $requestKey, 'request_digest' => $digest,
            'resource' => $resource, 'operation' => $operation,
            'policy_digest' => $this->policyDigest($definition, $command),
            'payload' => $this->encrypter->encryptString($payload),
            'status' => 'pending', 'created_at' => time(),
            'expires_at' => time() + max(30, min(900, (int) config('mcp-hub.commands.ttl', 300))),
        ];
        try {
            DB::table('mcp_hub_commands')->insert($row);
        } catch (QueryException $error) {
            $existing = DB::table('mcp_hub_commands')->where('actor_key', $actorKey)->where('request_key', $requestKey)->first();
            if (! $existing) {
                throw $error;
            }
            return $this->sameRequest($existing, $digest, $actor);
        }
        return $this->receipt((object) $row, $actor);
    }

    public function status(Actor $actor, string $id, ?string $resource = null): array
    {
        $row = $this->owned($actor, $id);
        if ($resource !== null && $row->resource !== $resource) {
            throw new CommandException('Proposal unavailable.', 404);
        }
        $this->authorized($actor, $row->resource, $row->operation);
        return $this->receipt($row, $actor);
    }

    /** Only for the authenticated application UI, never exposed in the Hub API. */
    public function preview(Actor $actor, string $id): array
    {
        $row = $this->owned($actor, $id);
        list($definition, $command) = $this->authorized($actor, $row->resource, $row->operation);
        if ($row->status === 'pending' && ! hash_equals($row->policy_digest, $this->policyDigest($definition, $command))) {
            throw new CommandException('Command policy changed.');
        }
        $payload = $row->payload !== '' ? Json::decode($this->encrypter->decryptString($row->payload), 32) : [];
        return ['receipt' => $this->receipt($row, $actor), 'resource' => $row->resource,
            'input' => isset($payload['input']) ? InputSchema::validate($command['input_schema'], $payload['input']) : [],
            'before' => $payload['before'] ?? []];
    }

    public function confirm(Actor $actor, string $id, string $decision): array
    {
        if (! in_array($decision, ['approve', 'cancel'], true)) {
            throw new CommandException('Invalid decision.', 422);
        }
        return DB::transaction(function () use ($actor, $id, $decision) {
            $row = $this->owned($actor, $id, true);
            list($definition, $command) = $this->authorized($actor, $row->resource, $row->operation);
            if ($row->status === 'pending' && ! hash_equals($row->policy_digest, $this->policyDigest($definition, $command))) {
                throw new CommandException('Command policy changed. Prepare a new proposal.');
            }
            if ($row->status === 'committed' || $row->status === 'cancelled') {
                return $this->receipt($row, $actor);
            }
            if ($row->status !== 'pending' || $row->expires_at <= time()) {
                throw new CommandException('Proposal expired or unavailable.', 410);
            }
            if ($decision === 'cancel') {
                DB::table('mcp_hub_commands')->where('id', $id)->update(['status' => 'cancelled', 'payload' => '']);
                $row->status = 'cancelled';
                return $this->receipt($row, $actor);
            }
            // Obtain a write lock before executing business code, including on SQLite.
            if (DB::table('mcp_hub_commands')->where('id', $id)->where('status', 'pending')
                ->update(['status' => 'committing']) !== 1) {
                throw new CommandException('Proposal is already being processed.');
            }
            $prepared = Json::decode($this->encrypter->decryptString($row->payload), 32);
            if (! is_array($prepared) || ! is_array($prepared['state'] ?? null)) {
                throw new CommandException('Prepared command is invalid.');
            }
            $input = InputSchema::validate($command['input_schema'], $prepared['input']);
            app($command['handler'])->commit($actor, $row->operation, $input, $prepared['state']);
            DB::table('mcp_hub_commands')->where('id', $id)->update(['status' => 'committed', 'payload' => '']);
            $row->status = 'committed';
            return $this->receipt($row, $actor);
        });
    }

    private function authorized(Actor $actor, string $resource, string $operation): array
    {
        $definition = $this->registry->resources()[$resource] ?? null;
        $command = $definition['commands'][$operation] ?? null;
        if (! $this->connection->get('enabled') || ! config('mcp-hub.commands.enabled') || ! $command
            || ! app($definition['policy'])->allows($actor, $resource, $operation)) {
            throw new CommandException('Command access denied.', 403);
        }
        $model = new $definition['model'];
        if ($model->getConnectionName() !== null && $model->getConnectionName() !== config('database.default')) {
            throw new CommandException('Commands require the default transaction connection.', 422);
        }
        return [$definition, $command];
    }

    private function owned(Actor $actor, string $id, bool $lock = false)
    {
        if (! preg_match('/^prop_[a-p]{48}$/D', $id)) {
            throw new CommandException('Proposal unavailable.', 404);
        }
        $query = DB::table('mcp_hub_commands')->where('id', $id)->where('actor_key', $this->actorKey($actor));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new CommandException('Proposal unavailable.', 404);
        }
        return $row;
    }

    private function actorKey(Actor $actor): string
    {
        return hash('sha256', Json::encode([$this->connection->get('app_key'), $this->connection->get('environment'),
            $this->connection->get('source_url'), $actor->reference, $actor->tenant]));
    }

    private function policyDigest(array $definition, array $command): string
    {
        return hash('sha256', Json::encode([$definition, $command]));
    }

    private function sameRequest($row, string $digest, Actor $actor): array
    {
        if (! hash_equals($row->request_digest, $digest)) {
            throw new CommandException('Idempotency key was already used for another command.');
        }
        return $this->receipt($row, $actor);
    }

    private function receipt($row, Actor $actor): array
    {
        $status = $row->status === 'pending' && $row->expires_at <= time() ? 'expired' : $row->status;
        return ['proposal_ref' => $row->id, 'status' => $status, 'operation' => $row->operation,
            'requires_confirmation' => $status === 'pending',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $row->expires_at), 'tenant_id' => $actor->tenant];
    }
}
