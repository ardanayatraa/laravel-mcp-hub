<?php

namespace McpHub\Laravel\Commands;

use RuntimeException;

final class CommandException extends RuntimeException
{
    private $status;

    public function __construct(string $message, int $status = 409)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}
