<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Isolated package test</title></head>
<body><main><h1>Isolated package test</h1><form method="post" action="{{ url('/fixture/login') }}">@csrf
<label>Test user <select name="user"><option value="1">User one</option><option value="2">User two</option></select></label>
<label>Fixture password <input name="password" type="password" required></label>
<button type="submit">Sign in</button></form></main></body></html>
