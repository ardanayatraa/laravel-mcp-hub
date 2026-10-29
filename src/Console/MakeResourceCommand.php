<?php

namespace McpHub\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

final class MakeResourceCommand extends Command
{
    protected $signature = 'make:mcp-resource {resource} {--model= : Fully qualified Eloquent model} {--dry-run}';
    protected $description = 'Generate a closed resource policy and reusable CRUD adapter without enabling access';

    public function handle(): int
    {
        $resource = $this->argument('resource');
        $model = ltrim((string) $this->option('model'), '\\');
        if (! is_string($resource) || ! preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $resource)
            || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $model)) {
            $this->error('Use a resource key and a fully qualified application model.');
            return 2;
        }
        try {
            $reflection = new ReflectionClass($model);
            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                throw new \RuntimeException();
            }
            $namespace = trim(app()->getNamespace(), '\\').'\\Mcp';
            $class = Str::studly($resource);
            $policy = $namespace.'\\'.$class.'Policy';
            $handler = $namespace.'\\'.$class.'Commands';
            $files = [
                app_path('Mcp/'.$class.'Policy.php') => $this->stub('policy', ['namespace' => $namespace, 'class' => $class]),
                app_path('Mcp/'.$class.'Commands.php') => $this->stub('commands', ['namespace' => $namespace,
                    'class' => $class, 'resource' => $resource]),
                config_path('mcp-hub-resources/'.$resource.'.php') => "<?php\n\nreturn ".var_export([
                    'model' => $model, 'policy' => $policy, 'enabled' => false, 'read' => false,
                    'fields' => [], 'lookup' => null, 'filters' => [], 'writable_fields' => [], 'commands' => [],
                ], true).";\n",
            ];
            foreach (array_keys($files) as $path) {
                if (file_exists($path) || is_link($path)) {
                    $this->error('A generated target already exists. No files were changed.');
                    return 1;
                }
            }
            if (! $this->option('dry-run')) {
                $created = [];
                try {
                    foreach ($files as $path => $content) {
                        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
                            throw new \RuntimeException();
                        }
                        $handle = fopen($path, 'x');
                        if (! $handle) {
                            throw new \RuntimeException();
                        }
                        $created[] = $path;
                        try {
                            if (fwrite($handle, $content) !== strlen($content)) {
                                throw new \RuntimeException();
                            }
                        } finally {
                            fclose($handle);
                        }
                    }
                } catch (Throwable $error) {
                    foreach ($created as $path) {
                        unlink($path);
                    }
                    throw $error;
                }
            }
            $this->info($this->option('dry-run') ? 'Would create three closed resource files.' : 'Created three closed resource files.');
            $this->line('Implement the application policy, review readable fields and command schemas, then enable the resource explicitly.');
            $this->line('CRUD adapter: '.$handler.'. No permissions were granted.');
            return 0;
        } catch (Throwable $error) {
            $this->error('Resource generation failed. Check the model and destination paths.');
            return 1;
        }
    }

    private function stub(string $name, array $values): string
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/resources/stubs/'.$name.'.stub');
        foreach ($values as $key => $value) {
            $content = str_replace('{{'.$key.'}}', $value, $content);
        }
        return $content;
    }
}
