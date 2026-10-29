<?php

namespace McpHub\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use McpHub\Laravel\Security\FieldPrivacy;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Discovery
{
    public function candidates(bool $schema = false): array
    {
        $out = [];
        $count = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (++$count > 5000) {
                throw new RuntimeException('Application source scan limit exceeded.');
            }
            if ($file->isLink() || ! $file->isFile() || $file->getExtension() !== 'php' || $file->getSize() > 262144) {
                continue;
            }
            foreach ($this->classes(file_get_contents($file->getPathname())) as $class) {
                $reflection = new ReflectionClass($class);
                if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                    continue;
                }
                // Reflection never constructs or boots a model, and never reads records.
                $defaults = $reflection->getDefaultProperties();
                $table = $defaults['table'] ?? Str::snake(Str::pluralStudly($reflection->getShortName()));
                $row = ['model' => $class, 'table' => $table, 'enabled' => false, 'fields' => []];
                if ($schema) {
                    if (! is_string($table) || ! preg_match('/^[a-zA-Z0-9_.]{1,128}$/D', $table)) {
                        throw new RuntimeException('Model table requires explicit review.');
                    }
                    $columns = DB::connection($defaults['connection'] ?? null)->getSchemaBuilder()->getColumnListing($table);
                    $row['fields'] = array_values(array_filter($columns, function ($field) use ($defaults) {
                        return ! FieldPrivacy::blocked($field) && ! in_array($field, $defaults['hidden'] ?? [], true);
                    }));
                }
                $out[] = $row;
                if (count($out) > 200) {
                    throw new RuntimeException('Model candidate limit exceeded.');
                }
            }
        }
        usort($out, function ($a, $b) { return strcmp($a['model'], $b['model']); });
        return $out;
    }

    private function classes(string $source): array
    {
        $namespace = '';
        $out = [];
        $tokens = token_get_all($source);
        for ($i = 0, $size = count($tokens); $i < $size; $i++) {
            if (! is_array($tokens[$i])) {
                continue;
            }
            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                while (++$i < $size && ! in_array($tokens[$i], [';', '{'], true)) {
                    $namespace .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                }
                $namespace = trim($namespace);
            } elseif ($tokens[$i][0] === T_CLASS) {
                $next = $i + 1;
                while ($next < $size && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $next++;
                }
                if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $out[] = ($namespace !== '' ? $namespace.'\\' : '').$tokens[$next][1];
                }
            }
        }
        return $out;
    }
}
