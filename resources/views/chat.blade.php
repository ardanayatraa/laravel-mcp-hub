<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mcp-hub::messages.chat_title') }}</title>
</head>
<body class="mcp-page">
    @include('mcp-hub::chat-widget')
</body>
</html>
