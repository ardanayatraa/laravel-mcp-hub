<?php

namespace McpHub\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class NativeDatabaseTest extends TestCase
{
    public function test_independent_postgres_transactions_commit_once_and_reject_stale_proposals(): void
    {
        if (! getenv('MCP_HUB_TEST_PG_DATABASE')) {
            $this->markTestSkipped('Optional disposable PostgreSQL fixture is not configured.');
        }
        $this->assertTrue(extension_loaded('pdo_pgsql'), 'Enable pdo_pgsql for this native database check.');
        $directory = __DIR__.'/../build/native-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700, true);
        $file = $directory.'/connection.json';
        file_put_contents($file, json_encode(['host' => getenv('MCP_HUB_TEST_PG_HOST') ?: '127.0.0.1',
            'port' => getenv('MCP_HUB_TEST_PG_PORT') ?: '5432', 'database' => getenv('MCP_HUB_TEST_PG_DATABASE'),
            'username' => getenv('MCP_HUB_TEST_PG_USER'), 'password' => getenv('MCP_HUB_TEST_PG_PASSWORD'),
            'schema' => 'mcp_fixture_'.bin2hex(random_bytes(12))]));
        chmod($file, 0600);
        $autoload = null;
        foreach (get_included_files() as $included) {
            if (substr(str_replace('\\', '/', $included), -20) === '/vendor/autoload.php') { $autoload = $included; break; }
        }
        $command = [PHP_BINARY, '-d', 'extension=php_pdo_pgsql.dll', __DIR__.'/Fixtures/transaction-worker.php', $autoload, $file];
        if (PHP_OS_FAMILY !== 'Windows') { array_splice($command, 1, 2); }
        $run = function (array $arguments) use ($command) {
            $process = new Process(array_merge($command, $arguments));
            $process->setTimeout(30);
            return $process;
        };
        $result = function ($process) {
            $this->assertSame(0, $process->wait(), 'Native fixture worker failed; private details are suppressed.');
            $decoded = json_decode($process->getOutput(), true);
            $this->assertIsArray($decoded);
            return $decoded;
        };
        $execute = function (array $arguments) use ($run, $result) {
            $process = $run($arguments); $process->start(); return $result($process);
        };
        $round = 0;
        $startTogether = function (array $workers) use (&$round, $directory) {
            $barrier = $directory.'/barrier-'.(++$round);
            foreach ($workers as $worker) { $worker->setEnv(['MCP_HUB_TEST_BARRIER' => $barrier]); $worker->start(); }
            $deadline = microtime(true) + 10;
            while (count(glob($barrier.'.*.ready')) < count($workers) && microtime(true) < $deadline) { usleep(1000); }
            $this->assertCount(count($workers), glob($barrier.'.*.ready'), 'Independent workers did not reach the race barrier.');
            file_put_contents($barrier.'.go', 'go');
        };
        try {
            $this->assertTrue($execute(['init'])['initialized']);
            $proposal = $execute(['prepare', 'First approved value'])['proposal_ref'];
            $workers = [$run(['confirm', $proposal]), $run(['confirm', $proposal])];
            $startTogether($workers);
            foreach ($workers as $worker) { $this->assertSame('committed', $result($worker)['status']); }
            $this->assertSame(1, $execute(['inspect'])['writes']);
            $first = $execute(['prepare', 'Competing value A'])['proposal_ref'];
            $second = $execute(['prepare', 'Competing value B'])['proposal_ref'];
            $workers = [$run(['confirm', $first]), $run(['confirm', $second])];
            $startTogether($workers);
            $statuses = array_map(function ($worker) use ($result) { return $result($worker)['status']; }, $workers);
            sort($statuses);
            $this->assertSame(['committed', 'denied'], $statuses);
            $this->assertSame(2, $execute(['inspect'])['writes']);
            $this->assertSame(404, $execute(['confirm', $proposal, '2'])['code']);
            $this->assertSame(2, $execute(['inspect'])['writes']);
            $reference = $execute(['reference'])['record_ref'];
            $arguments = ['prepare', 'Idempotent input', '1', 'native-idempotency-fixed', $reference];
            $workers = [$run($arguments), $run($arguments)];
            $startTogether($workers);
            $receipts = array_map($result, $workers);
            $this->assertSame($receipts[0]['proposal_ref'], $receipts[1]['proposal_ref']);
            $this->assertSame(4, $execute(['inspect'])['proposals']);
            $workers = [$run(['export', 'native-export-same-key']), $run(['export', 'native-export-same-key'])];
            $startTogether($workers);
            $receipts = array_map($result, $workers);
            foreach ($receipts as $receipt) { $this->assertContains($receipt['status'], ['pending', 'denied']); }
            $pending = array_values(array_filter($receipts, function ($receipt) { return $receipt['status'] === 'pending'; }));
            $this->assertNotEmpty($pending);
            $this->assertSame($pending[0]['export_ref'], $execute(['export', 'native-export-same-key'])['export_ref']);
            $this->assertSame(1, $execute(['inspect'])['exports']);
            $workers = [$run(['export', 'native-export-second-key']), $run(['export', 'native-export-third-key'])];
            $startTogether($workers);
            $receipts = array_map($result, $workers);
            $this->assertSame(2, $execute(['inspect'])['exports']);
        } finally {
            $cleanup = $execute(['cleanup']);
            $this->assertTrue($cleanup['cleaned']);
            // Resolve both paths before recursively removing this test's own directory.
            if (realpath(dirname($directory)) === realpath(__DIR__.'/../build') && strpos(basename($directory), 'native-') === 0) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($directory);
            }
        }
    }
}
