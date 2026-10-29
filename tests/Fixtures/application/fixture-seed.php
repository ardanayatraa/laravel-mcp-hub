<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing']) || config('fixture.enabled') !== true || config('database.default') !== 'sqlite') {
    exit(2);
}
$schema = \Illuminate\Support\Facades\Schema::class;
if (! $schema::hasColumn('users', 'active')) {
    $schema::table('users', function ($table) { $table->boolean('active')->default(true); });
}
if (! $schema::hasTable('fixture_orders')) {
    $schema::create('fixture_orders', function ($table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('user_id')->index();
        $table->string('title');
        $table->string('status');
        $table->decimal('total', 12, 2);
        $table->string('private_note');
        $table->timestamps();
    });
}
if (\App\Mcp\FixtureUser::query()->count() === 0) {
    foreach ([1, 2] as $id) {
        \App\Mcp\FixtureUser::query()->create(['id' => $id, 'name' => 'Test user '.$id,
            'email' => 'fixture'.$id.'@example.test', 'password' => password_hash('Fixture-Only-Aa99', PASSWORD_BCRYPT), 'active' => true]);
        \App\Mcp\FixtureOrder::query()->create(['user_id' => $id, 'title' => 'Order '.$id,
            'status' => 'paid', 'total' => 100 * $id, 'private_note' => 'private-value-canary']);
    }
}
echo "Isolated fixture ready.\n";
