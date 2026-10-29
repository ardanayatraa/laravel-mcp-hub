<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Test fixture only: absent from package routes and blocked outside local/testing.
if (! app()->environment(['local', 'testing']) || config('fixture.enabled') !== true) {
    return;
}
Route::get('/fixture/login', function () { return view('fixture-login'); });
Route::post('/fixture/login', function (Request $request) {
    $input = $request->validate(['user' => 'required|in:1,2', 'password' => 'required|string']);
    abort_unless(is_string(config('fixture.password')) && strlen(config('fixture.password')) >= 16
        && hash_equals(config('fixture.password'), $input['password']), 403);
    Auth::login(\App\Mcp\FixtureUser::query()->findOrFail($input['user']));
    $request->session()->regenerate();
    return redirect('/mcp-hub/chat');
});
Route::get('/fixture/orders', function () {
    abort_unless(Auth::check(), 401);
    return \App\Mcp\FixtureOrder::query()->where('user_id', Auth::id())->get(['title', 'status', 'total']);
});
