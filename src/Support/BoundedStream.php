<?php

namespace McpHub\Laravel\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Enforce the response limit while downloading, before buffering the body. */
final class BoundedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private $limit;
    private $deadline;

    public function __construct(int $limit, float $deadline)
    {
        $this->stream = Utils::streamFor('');
        $this->limit = $limit;
        $this->deadline = $deadline;
    }

    public function write($string): int
    {
        if (microtime(true) > $this->deadline || $this->stream->getSize() + strlen($string) > $this->limit) {
            throw new RuntimeException('Hub response limit exceeded.');
        }
        return $this->stream->write($string);
    }
}
