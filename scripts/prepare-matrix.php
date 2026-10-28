<?php

// Isolated development fixtures; never changes an application's Composer settings.
$versions = [
    '5.8' => ['5.8.38', '3.8.7', '^8.5'],
    '6' => ['^6.20.12', '4.18.0', '^8.5'],
    '7' => ['^7.30.3', '5.22.0', '^8.5'],
    '8' => ['^8.83.27', '6.53.1', '^9.6'],
    '9' => ['9.52.21', '7.59.0', '^9.6'],
    '10' => ['^10.50.3', '8.44.0', '^10.5'],
    '11' => ['11.45.0', '9.14.0', '^11.5'],
    '12' => ['12.64.0', '10.11.0', '^11.5'],
    '13' => ['13.38.0', '11.5.0', '^11.5'],
];
$version = $argv[1] ?? '';
if (! isset($versions[$version])) {
    fwrite(STDERR, "Choose Laravel 5.8, 6, 7, 8, 9, 10, 11, 12 or 13.\n");
    exit(1);
}
list($framework, $testbench, $phpunit) = $versions[$version];
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/composer.json'), true);
$manifest['require-dev'] = [
    'laravel/framework' => $framework,
    'orchestra/testbench-core' => $testbench,
    'phpunit/phpunit' => $phpunit,
    'mockery/mockery' => '^1.3',
];
$manifest['autoload']['psr-4']['McpHub\\Laravel\\'] = $root.'/src/';
$manifest['autoload-dev']['psr-4']['McpHub\\Laravel\\Tests\\'] = $root.'/tests/';
// Specific, reviewed historical advisories are allowed only in these throwaway
// fixtures. They remain in Composer's audit output; unknown advisories still block.
$exceptions = json_decode(file_get_contents(__DIR__.'/legacy-advisories.json'), true);
if ($exceptions) {
    $manifest['config']['audit']['ignore'] = $exceptions;
}
$directory = $root.'/.compat/'.$version;
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo ".compat/".$version."/composer.json\n";
