<?php

namespace McpHub\Laravel\Security;

use Illuminate\Http\Request;
use McpHub\Laravel\Connection;

final class SourceAccess
{
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function allows(Request $request): bool
    {
        try {
            $key = (string) $this->connection->get('app_token', '');
            return $this->connection->get('enabled') && strlen($key) >= 32
                && (! $this->connection->get('require_https') || $request->isSecure())
                && hash_equals($key, (string) $request->bearerToken());
        } catch (\Throwable $error) {
            return false;
        }
    }
}
