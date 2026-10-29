<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use McpHub\Laravel\Support\Json;

final class DoctorCommand extends Command
{
    protected $signature = 'mcp-hub:doctor {--online} {--json}';
    protected $description = 'Check configuration and optionally list Hub tools without reading records';

    public function handle(): int
    {
        $result = app(\McpHub\Laravel\Doctor::class)->inspect((bool) $this->option('online'));
        $checks = $result['checks'];
        if ($this->option('json')) {
            $this->line(Json::encode(['checks' => $checks, 'ok' => ! in_array(false, $checks, true)]));
        } else {
            foreach ($checks as $name => $ok) {
                $this->line($name.': '.($ok ? 'OK' : 'FAIL'));
            }
        }
        return in_array(false, $checks, true) ? 1 : 0;
    }
}
