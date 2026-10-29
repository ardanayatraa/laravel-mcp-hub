<?php

namespace McpHub\Laravel;

use RuntimeException;

final class HubException extends RuntimeException
{
    private $errorCode;
    private $status;

    public function __construct(string $errorCode = 'UPSTREAM_UNAVAILABLE', int $status = 503)
    {
        parent::__construct('MCP Hub request failed.');
        $this->errorCode = $errorCode;
        $this->status = $status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
