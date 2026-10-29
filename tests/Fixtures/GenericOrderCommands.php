<?php

namespace McpHub\Laravel\Tests\Fixtures;

final class GenericOrderCommands extends \McpHub\Laravel\Commands\EloquentCommands
{
    protected function resource(): string
    {
        return 'orders';
    }
}
