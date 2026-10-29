<?php

namespace McpHub\Laravel\Http;

final class AssetController
{
    public function show(string $asset)
    {
        $types = ['chat.js' => 'text/javascript', 'chat.css' => 'text/css', 'settings.js' => 'text/javascript'];
        if (! isset($types[$asset])) {
            abort(404);
        }
        $file = dirname(__DIR__, 2).'/resources/assets/'.$asset;
        if (! is_file($file)) {
            abort(404);
        }
        $contents = file_get_contents($file);
        return response($contents, 200, ['Content-Type' => $types[$asset].'; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=3600',
            'ETag' => '"'.hash('sha256', $contents).'"']);
    }
}
