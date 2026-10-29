<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use McpHub\Laravel\Discovery;
use McpHub\Laravel\Support\Json;
use Throwable;

final class DiscoverCommand extends Command
{
    protected $signature = 'mcp-hub:discover {--schema : Read column metadata, never business records} {--json}';
    protected $description = 'List application model candidates without enabling access';

    public function handle(Discovery $discovery): int
    {
        try {
            $data = ['laravel' => app()->version(), 'php' => PHP_VERSION, 'candidates' => $discovery->candidates((bool) $this->option('schema'))];
            if ($this->option('json')) {
                $this->line(Json::encode($data));
            } else {
                $this->line('Laravel '.$data['laravel'].' / PHP '.$data['php']);
                foreach ($data['candidates'] as $candidate) {
                    $this->line($candidate['model'].' (closed): '.implode(', ', $candidate['fields']));
                }
                $this->info('Candidates only. No resources or permissions were enabled.');
            }
            return 0;
        } catch (Throwable $error) {
            $this->error('Candidate discovery failed. Review application classes and database metadata access.');
            return 1;
        }
    }
}
