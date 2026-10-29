<?php

namespace McpHub\Laravel\Security;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use McpHub\Laravel\Connection;
use McpHub\Laravel\Support\Json;
use RuntimeException;
use Throwable;

/** Opaque references bound to installation, environment, resource, actor and tenant. */
final class RecordReferences
{
    private $encrypter;
    private $connection;

    public function __construct(EncrypterContract $encrypter, Connection $connection)
    {
        $this->encrypter = $encrypter;
        $this->connection = $connection;
    }

    public function encode(string $resource, Actor $actor, $key): string
    {
        $key = $this->key($key);
        if ($this->legacy()) {
            return 'record_'.$key;
        }
        // Laravel owns encryption and MAC verification. Letter encoding keeps
        // opaque transport metadata from resembling phone/card numbers.
        $encrypted = $this->cipher($resource, $actor)->encrypt($key, false);
        $ref = 'ref_'.strtr(bin2hex($encrypted), '0123456789abcdef', 'abcdefghijklmnop');
        if (strlen($ref) > 1024) {
            throw new RuntimeException('Record reference is too large.');
        }
        return $ref;
    }

    public function decode(string $resource, Actor $actor, string $ref): string
    {
        try {
            if ($this->legacy() && preg_match('/^record_([a-zA-Z0-9_-]{1,72})$/D', $ref, $match)) {
                return $this->key($match[1]);
            }
            if (! preg_match('/^ref_[a-p]{128,1020}$/D', $ref) || (strlen($ref) - 4) % 2 !== 0) {
                throw new RuntimeException();
            }
            $encrypted = hex2bin(strtr(substr($ref, 4), 'abcdefghijklmnop', '0123456789abcdef'));
            $decoded = base64_decode($encrypted, true);
            if ($decoded === false || base64_encode($decoded) !== $encrypted) {
                throw new RuntimeException();
            }
            return $this->key($this->cipher($resource, $actor)->decrypt($encrypted, false));
        } catch (Throwable $error) {
            throw new RuntimeException('Record access denied.');
        }
    }

    private function cipher(string $resource, Actor $actor): Encrypter
    {
        if (! method_exists($this->encrypter, 'getKey')) {
            throw new RuntimeException('Application encryption is unavailable.');
        }
        $context = Json::encode(['mcp-hub-record-v1', $this->connection->get('app_key'),
            $this->connection->get('environment') ?: app()->environment(),
            rtrim((string) $this->connection->get('source_url'), '/'), $resource, $actor->reference, $actor->tenant]);
        return new Encrypter(hash_hmac('sha256', $context, $this->encrypter->getKey(), true), 'AES-256-CBC');
    }

    private function legacy(): bool
    {
        $mode = config('mcp-hub.record_references', 'opaque');
        if (! in_array($mode, ['opaque', 'legacy'], true)) {
            throw new RuntimeException('Invalid record reference mode.');
        }
        return $mode === 'legacy';
    }

    private function key($value): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[a-zA-Z0-9_-]{1,72}$/D', (string) $value)) {
            throw new RuntimeException('Unsupported record identifier.');
        }
        return (string) $value;
    }
}
