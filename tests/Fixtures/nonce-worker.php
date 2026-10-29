<?php

require $argv[1];

$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => $argv[2], 'prefix' => '']);
$capsule->getConnection()->statement('PRAGMA busy_timeout = 5000');
$container = new \Illuminate\Container\Container();
$container->instance('db', $capsule->getDatabaseManager());
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$accepted = 0;
for ($i = 0; $i < 10; $i++) {
    try {
        (new \McpHub\Laravel\Security\NonceStore())->consume(hash('sha256', 'one-disposable-assertion'), time() + 120);
        $accepted++;
    } catch (\RuntimeException $error) {
        // Concurrent duplicates must fail closed.
    }
}
echo json_encode(['accepted' => $accepted]);
