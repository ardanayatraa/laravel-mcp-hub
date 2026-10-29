<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing']) || config('fixture.enabled') !== true) {
    exit(2);
}
$user = \App\Mcp\FixtureUser::query()->findOrFail((int) ($argv[2] ?? 1));
try {
    $input = isset($argv[3]) ? json_decode(file_get_contents($argv[3]), true) : [];
    if ($argv[1] === 'gateway') {
        $result = app(\McpHub\Laravel\Gateway::class)->complete($user, $input);
    } else {
        $assertion = app(\McpHub\Laravel\Security\Assertions::class)->delegate($user);
        $result = app(\McpHub\Laravel\HubClient::class)->rpc($argv[1], $input, ['X-Hub-Delegation' => $assertion]);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $error) {
    echo json_encode(['error' => 'fixture_call_failed']);
    exit(1);
}
