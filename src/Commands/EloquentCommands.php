<?php

namespace McpHub\Laravel\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use McpHub\Laravel\Contracts\CommandHandler;
use McpHub\Laravel\Contracts\WritePolicy;
use McpHub\Laravel\Registry;
use McpHub\Laravel\Security\Actor;
use McpHub\Laravel\Support\InputSchema;
use McpHub\Laravel\Support\Json;

/** Reusable transactional CRUD; the host policy owns every business permission. */
abstract class EloquentCommands implements CommandHandler
{
    abstract protected function resource(): string;

    final public function prepare(Actor $actor, string $operation, array $input): PreparedCommand
    {
        list($definition, $policy, $schema) = $this->context($actor, $operation);
        $record = $operation === 'create' ? null : $this->record($actor, $definition, $policy, $input['ref'] ?? '', false);
        $visible = $this->validated($actor, $operation, $input, $record, $definition, $policy, $schema);
        $defaults = $operation === 'create' ? $this->defaults($actor, $policy, $visible) : [];
        $before = [];
        if ($record) {
            foreach ($definition['fields'] as $field => $type) {
                $before[$field] = \McpHub\Laravel\Support\FieldValue::present($record->getAttribute($field), $type);
            }
        }
        return new PreparedCommand($visible, ['version' => $record ? $this->version($record) : null,
            'defaults_digest' => $this->digest($defaults)], $before);
    }

    final public function commit(Actor $actor, string $operation, array $input, array $state): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new CommandException('A command transaction is required.');
        }
        list($definition, $policy, $schema) = $this->context($actor, $operation);
        $record = $operation === 'create' ? null : $this->record($actor, $definition, $policy, $input['ref'] ?? '', true);
        if ($record && ! hash_equals((string) ($state['version'] ?? ''), $this->version($record))) {
            throw new CommandException('Record changed. Prepare a new proposal.');
        }
        $visible = $this->validated($actor, $operation, $input, $record, $definition, $policy, $schema);
        if (! hash_equals($this->digest($input), $this->digest($visible))) {
            throw new CommandException('Business rules changed. Prepare a new proposal.');
        }
        if ($operation === 'delete') {
            if (! $record->delete()) {
                throw new CommandException('Record could not be deleted.');
            }
            return;
        }
        $values = $visible;
        unset($values['ref']);
        if ($operation === 'create') {
            $defaults = $this->defaults($actor, $policy, $visible);
            if (! hash_equals((string) ($state['defaults_digest'] ?? ''), $this->digest($defaults))) {
                throw new CommandException('Record defaults changed. Prepare a new proposal.');
            }
            $record = new $definition['model'];
            $values = array_merge($defaults, $values);
        }
        if (! $record->forceFill($values)->save()) {
            throw new CommandException('Record could not be saved.');
        }
        // Observers, database triggers and changing membership cannot expand scope.
        $current = $policy->scope($definition['model']::query(), $actor)->whereKey($record->getKey())->first();
        if (! $current) {
            throw new CommandException('Record access changed.', 403);
        }
        foreach ($visible as $field => $expected) {
            if ($field === 'ref') {
                continue;
            }
            $actual = $current->getAttribute($field);
            $type = $schema['properties'][$field]['type'];
            if (in_array('number', (array) $type, true) && is_numeric($actual)) {
                $actual = (float) $actual;
                $expected = $expected === null ? null : (float) $expected;
            } elseif (in_array('integer', (array) $type, true) && is_numeric($actual) && (int) $actual == $actual) {
                $actual = (int) $actual;
            } elseif (in_array('boolean', (array) $type, true) && in_array($actual, [0, 1, '0', '1', true, false], true)) {
                $actual = (bool) $actual;
            }
            if ($actual !== $expected) {
                throw new CommandException('Saved values differ from the approved proposal.');
            }
        }
    }

    private function context(Actor $actor, string $operation): array
    {
        $definition = app(Registry::class)->resources()[$this->resource()] ?? null;
        $command = $definition['commands'][$operation] ?? null;
        $policy = $definition ? app($definition['policy']) : null;
        if (! $policy instanceof WritePolicy || ! $command || $command['handler'] !== static::class
            || ! $policy->allows($actor, $this->resource(), $operation)) {
            throw new CommandException('Command access denied.', 403);
        }
        return [$definition, $policy, $command['input_schema']];
    }

    private function validated(Actor $actor, string $operation, array $input, ?Model $record,
        array $definition, WritePolicy $policy, array $schema): array
    {
        InputSchema::validate($schema, $input);
        $business = $input;
        unset($business['ref']);
        $visible = $policy->validate($actor, $this->resource(), $operation, $business, $record);
        if (array_diff(array_keys($visible), $definition['writable_fields']) || ($operation === 'delete' && $visible !== [])) {
            throw new CommandException('Business values exceed the reviewed fields.', 422);
        }
        if (isset($input['ref'])) {
            $visible['ref'] = $input['ref'];
        }
        return InputSchema::validate($schema, $visible);
    }

    private function defaults(Actor $actor, WritePolicy $policy, array $visible): array
    {
        $defaults = $policy->createAttributes($actor, $this->resource());
        if (count($defaults) > 50 || array_intersect_key($defaults, $visible)) {
            throw new CommandException('Server attributes conflict with the proposal.', 422);
        }
        foreach ($defaults as $field => $value) {
            if (! is_string($field) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/D', $field)
                || (! is_scalar($value) && $value !== null)) {
                throw new CommandException('Server attributes are invalid.', 422);
            }
        }
        return $defaults;
    }

    private function record(Actor $actor, array $definition, WritePolicy $policy, string $ref, bool $lock): Model
    {
        try {
            $key = app(\McpHub\Laravel\Security\RecordReferences::class)->decode($this->resource(), $actor, $ref);
        } catch (\Throwable $error) {
            throw new CommandException('Record unavailable.', 403);
        }
        $query = $policy->scope($definition['model']::query(), $actor)->whereKey($key);
        $record = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $record) {
            throw new CommandException('Record unavailable.', 403);
        }
        return $record;
    }

    private function version(Model $record): string
    {
        return $this->digest($record->getAttributes());
    }

    private function digest(array $values): string
    {
        ksort($values);
        return hash('sha256', Json::encode($values));
    }
}
