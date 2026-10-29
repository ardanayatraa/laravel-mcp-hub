<?php

namespace App\Mcp;

final class FixtureOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'fixture_orders';
    protected $guarded = [];
    protected $hidden = ['private_note', 'user_id'];
}
