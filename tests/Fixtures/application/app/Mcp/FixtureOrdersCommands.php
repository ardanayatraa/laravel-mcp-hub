<?php

namespace App\Mcp;

final class FixtureOrdersCommands extends \McpHub\Laravel\Commands\EloquentCommands
{
    protected function resource(): string
    {
        return 'orders';
    }
}
