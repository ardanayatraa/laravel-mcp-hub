<?php

namespace McpHub\Laravel\Exports;

use Illuminate\Contracts\Encryption\Encrypter;

/** Private, encrypted, application-local artifacts. No public storage disk. */
final class ExportFiles
{
    private $encrypter;

    public function __construct(Encrypter $encrypter)
    {
        $this->encrypter = $encrypter;
    }

    public function directory(): string
    {
        $directory = storage_path('app/mcp-hub/exports');
        if ((! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory))
            || is_link($directory)) {
            throw new ExportException('Private export storage is unavailable.', 503);
        }
        return $directory;
    }

    private function path(string $id): string
    {
        if (! preg_match('/^exp_[a-p]{48}$/D', $id)) {
            throw new ExportException('Export unavailable.', 404);
        }
        return $this->directory().'/'.$id.'.enc';
    }

    public function write(string $id, string $bytes): void
    {
        if (strlen($bytes) > 5242880) {
            throw new ExportException('Export is too large.', 413);
        }
        $path = $this->path($id);
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $handle = fopen($temporary, 'x');
            if (! $handle) {
                throw new ExportException('Private export storage is unavailable.', 503);
            }
            chmod($temporary, 0600);
            try {
                $encrypted = $this->encrypter->encryptString($bytes);
                if (fwrite($handle, $encrypted) !== strlen($encrypted)) {
                    throw new ExportException('Export could not be saved.', 503);
                }
            } finally {
                fclose($handle);
            }
            if (! rename($temporary, $path)) {
                throw new ExportException('Export could not be saved.', 503);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function read(string $id): string
    {
        $path = $this->path($id);
        if (! is_file($path) || is_link($path) || filesize($path) > 10000000) {
            throw new ExportException('Export unavailable.', 404);
        }
        return $this->encrypter->decryptString(file_get_contents($path));
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
}
