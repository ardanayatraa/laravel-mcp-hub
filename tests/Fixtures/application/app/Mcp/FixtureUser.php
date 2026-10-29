<?php

namespace App\Mcp;

final class FixtureUser extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'users';
    protected $guarded = [];
    protected $hidden = ['email', 'password', 'remember_token'];
}
