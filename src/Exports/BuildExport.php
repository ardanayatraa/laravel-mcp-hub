<?php

namespace McpHub\Laravel\Exports;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Queue payload contains only a random metadata reference, never records or identity. */
final class BuildExport implements ShouldQueue
{
    public $reference;
    public $connection;
    public $queue;
    public $tries = 1;
    public $timeout = 60;

    public function __construct(string $reference)
    {
        $this->reference = $reference;
        $this->connection = config('mcp-hub.exports.connection');
        $this->queue = config('mcp-hub.exports.queue', 'default');
    }

    public function handle(Exports $exports): void
    {
        $exports->build($this->reference);
    }
}
