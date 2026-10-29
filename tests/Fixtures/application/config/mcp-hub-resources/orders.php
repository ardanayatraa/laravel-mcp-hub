<?php

$fields = ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
    'status' => ['type' => 'string', 'maxLength' => 16, 'enum' => ['paid', 'pending']],
    'total' => ['type' => 'number', 'minimum' => 0, 'maximum' => 10000]];
$commands = [];
foreach (['create', 'update', 'delete'] as $operation) {
    $properties = $operation === 'delete' ? [] : $fields;
    if ($operation !== 'create') {
        $properties['ref'] = ['type' => 'string', 'maxLength' => 1024];
    }
    $commands[$operation] = ['handler' => \App\Mcp\FixtureOrdersCommands::class,
        'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties,
            'required' => $operation === 'create' ? ['title'] : ['ref']]];
}

return ['model' => \App\Mcp\FixtureOrder::class, 'policy' => \App\Mcp\FixtureOrdersPolicy::class,
    'enabled' => true, 'read' => true, 'fields' => ['title' => 'string', 'status' => 'string', 'total' => 'number'],
    'lookup' => 'title', 'filters' => ['status' => ['paid', 'pending']],
    'exports' => ['csv', 'xlsx', 'pdf'],
    'writable_fields' => ['title', 'status', 'total'], 'commands' => $commands];
