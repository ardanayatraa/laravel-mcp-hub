<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMcpHubCommandsTable extends Migration
{
    public function up()
    {
        Schema::create('mcp_hub_commands', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->string('actor_key', 64);
            $table->string('request_key', 64);
            $table->string('request_digest', 64);
            $table->string('policy_digest', 64);
            $table->string('resource', 48);
            $table->string('operation', 12);
            $table->string('status', 16);
            $table->longText('payload');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('expires_at')->index();
            $table->unique(['actor_key', 'request_key'], 'mcp_hub_command_idempotency');
        });
    }

    public function down()
    {
        Schema::dropIfExists('mcp_hub_commands');
    }
}
