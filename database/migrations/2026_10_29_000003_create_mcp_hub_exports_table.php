<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMcpHubExportsTable extends Migration
{
    public function up()
    {
        Schema::create('mcp_hub_exports', function (Blueprint $table) {
            $table->string('id', 52)->primary();
            $table->string('actor_key', 64);
            $table->string('request_key', 64);
            $table->string('request_digest', 64);
            $table->string('policy_digest', 64);
            $table->string('resource', 48);
            $table->string('format', 4);
            $table->string('status', 16);
            $table->longText('payload');
            $table->string('coverage', 16)->default('unknown');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('expires_at')->index();
            $table->unique(['actor_key', 'request_key']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('mcp_hub_exports');
    }
}
