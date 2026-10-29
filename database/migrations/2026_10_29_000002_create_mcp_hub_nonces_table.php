<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMcpHubNoncesTable extends Migration
{
    public function up()
    {
        Schema::create('mcp_hub_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary();
            $table->unsignedBigInteger('expires_at')->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mcp_hub_nonces');
    }
}
