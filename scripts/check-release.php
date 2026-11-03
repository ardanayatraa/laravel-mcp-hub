<?php

// Validate the actual distribution, then build an isolated test autoloader
// pointing at the extracted code rather than the source checkout.
$root = dirname(__DIR__);
$zip = new ZipArchive();
if ($zip->open($argv[1] ?? '') !== true) {
    throw new RuntimeException('Cannot open release archive.');
}
$required = ['composer.json', 'README.md', 'LICENSE', 'UPGRADE.md', 'SECURITY.md',
    'src/McpHubServiceProvider.php', 'config/mcp-hub.php',
    'database/migrations/2026_10_29_000002_create_mcp_hub_nonces_table.php',
    'resources/assets/chat.js', 'resources/views/settings.blade.php'];
$documents = ['composer.json', 'README.md', 'LICENSE', 'CHANGELOG.md', 'DEVELOPMENT.md',
    'UPGRADE.md', 'SECURITY.md', 'EXPORTS.md', 'RELEASING.md'];
$seen = [];
for ($i = 0; $i < $zip->numFiles; ++$i) {
    $name = $zip->getNameIndex($i);
    if (strpos($name, '..') !== false || strpos($name, '\\') !== false
        || (! in_array($name, $documents, true)
            && ! preg_match('~^(src|config|database|resources)/[A-Za-z0-9_./-]*$~D', $name))) {
        throw new RuntimeException('Unexpected release entry: '.$name);
    }
    $seen[] = $name;
}
if (array_diff($required, $seen)) {
    throw new RuntimeException('The release archive is missing runtime files.');
}
// Check every tracked runtime file, not just a small list of entry points.
foreach (['src', 'config', 'database', 'resources'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,
        FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile()) { continue; }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($zip->getFromName($relative) !== file_get_contents($file->getPathname())) {
            throw new RuntimeException('Archive runtime mismatch: '.$relative);
        }
    }
}
$directory = $root.'/build/release-'.bin2hex(random_bytes(8));
if (! mkdir($directory, 0700, true) || ! $zip->extractTo($directory)) {
    throw new RuntimeException('Cannot extract release archive.');
}
$zip->close();
$manifest = json_decode(file_get_contents($directory.'/composer.json'), true);
$manifest['autoload']['psr-4']['McpHub\\Laravel\\'] = $directory.'/src/';
$manifest['autoload-dev']['psr-4']['McpHub\\Laravel\\Tests\\'] = $root.'/tests/';
$fixture = $root.'/.compat/release';
if (! is_dir($fixture)) { mkdir($fixture, 0700, true); }
file_put_contents($fixture.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo 'Release archive: runtime files match; no credentials, tests, vendor or local state. Smoke fixture: .compat/release'.PHP_EOL;
