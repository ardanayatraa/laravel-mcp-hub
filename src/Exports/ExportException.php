<?php

namespace McpHub\Laravel\Exports;

final class ExportException extends \RuntimeException
{
    public function status(): int
    {
        return $this->getCode() ?: 403;
    }
}
