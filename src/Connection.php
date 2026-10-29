<?php

namespace McpHub\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;
use McpHub\Laravel\Support\Json;

final class Connection
{
    private $config;
    private $encrypter;

    public function __construct(Repository $config, Encrypter $encrypter)
    {
        $this->config = $config;
        $this->encrypter = $encrypter;
    }

    public function get(string $key, $default = null)
    {
        $value = $this->config->get('mcp-hub.'.$key);
        if ($key === 'enabled') {
            $saved = $this->saved();
            if ($value === false || ($saved['enabled'] ?? null) === false
                || ($value !== null && ! is_bool($value))) {
                return false;
            }
            return $value === true || ($saved['enabled'] ?? null) === true || (string) $this->get('url', '') !== '';
        }
        if ($value !== null && $value !== '') {
            return $value;
        }
        $saved = $this->saved();
        if (array_key_exists($key, $saved)) {
            return $saved[$key];
        }
        if ($key === 'environment') {
            return config('app.env', 'local');
        }
        return $default;
    }

    public function update(array $values): void
    {
        $this->locked('connection', function () use ($values) {
            $this->save(array_replace($this->saved(), $values));
        });
    }

    public function stored(): array
    {
        return $this->saved();
    }

    public function locked(string $name, callable $callback)
    {
        if (! in_array($name, ['connection', 'pairing'], true)) {
            throw new RuntimeException('Invalid connection lock.');
        }
        $directory = storage_path('app/mcp-hub');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Connection storage is unavailable.');
        }
        $lock = fopen($directory.'/'.$name.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Connection lock is unavailable.');
        }
        chmod($directory.'/'.$name.'.lock', 0600);
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another connection operation is in progress.');
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function save(array $values): void
    {
        $file = storage_path('app/mcp-hub/connection.enc');
        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0700, true) && ! is_dir(dirname($file))) {
            throw new RuntimeException('Connection storage is unavailable.');
        }
        $temporary = $file.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $payload = $this->encrypter->encryptString(Json::encode($values));
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                throw new RuntimeException('Connection could not be saved.');
            }
            chmod($temporary, 0600);
            if (! rename($temporary, $file)) {
                throw new RuntimeException('Connection could not be saved.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function saved(): array
    {
        $file = storage_path('app/mcp-hub/connection.enc');
        if (! is_file($file)) {
            return [];
        }
        $values = Json::decode($this->encrypter->decryptString(file_get_contents($file)));
        if (! is_array($values)) {
            throw new RuntimeException('Connection storage is invalid.');
        }
        return $values;
    }
}
