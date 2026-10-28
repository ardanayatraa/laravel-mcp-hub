<?php

namespace McpHub\Laravel\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

final class Actor
{
    /** @var Authenticatable */
    public $user;
    /** @var string */
    public $reference;
    /** @var string */
    public $tenant;

    public function __construct(Authenticatable $user, string $reference, string $tenant)
    {
        if ($reference === '' || $tenant === '' || strlen($reference) > 200 || strlen($tenant) > 200) {
            throw new InvalidArgumentException('Invalid actor context.');
        }
        $this->user = $user;
        $this->reference = $reference;
        $this->tenant = $tenant;
    }
}
