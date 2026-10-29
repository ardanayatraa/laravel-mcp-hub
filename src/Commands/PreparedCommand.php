<?php

namespace McpHub\Laravel\Commands;

/** The exact reviewed input is separate from private, encrypted commit state. */
final class PreparedCommand
{
    private $input;
    private $state;
    private $before;

    public function __construct(array $input, array $state = [], array $before = [])
    {
        $this->input = $input;
        $this->state = $state;
        $this->before = $before;
    }

    public function input(): array
    {
        return $this->input;
    }

    public function state(): array
    {
        return $this->state;
    }

    public function before(): array
    {
        return $this->before;
    }
}
