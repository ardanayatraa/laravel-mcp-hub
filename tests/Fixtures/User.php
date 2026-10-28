<?php

namespace McpHub\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    protected $guarded = [];
    protected $casts = ['active' => 'boolean'];
}
