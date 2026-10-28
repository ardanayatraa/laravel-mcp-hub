<?php

// Historical fixtures have known upstream advisories. Keep the full report
// visible and fail on any advisory that has not been reviewed.
$report = json_decode(file_get_contents($argv[1] ?? ''), true);
if (! is_array($report) || ! isset($report['advisories']) || ! array_key_exists('abandoned', $report)) {
    fwrite(STDERR, "Composer audit did not produce a valid report.\n");
    exit(1);
}
$baseline = json_decode(file_get_contents(__DIR__.'/legacy-advisories.json'), true);
$known = 0;
$unknown = [];
foreach ($report['advisories'] as $package => $advisories) {
    foreach ($advisories as $advisory) {
        $id = $advisory['advisoryId'] ?? '';
        if (! isset($baseline[$id])) {
            $unknown[] = $package.' '.$id;
        } else {
            ++$known;
        }
    }
}
echo "Historical fixture: ".$known." known upstream advisories (not fixed by this connector).\n";
foreach (array_keys($report['abandoned']) as $package) {
    echo "Abandoned historical dependency: ".$package."\n";
}
if ($unknown) {
    fwrite(STDERR, "Unreviewed advisories:\n".implode("\n", $unknown)."\n");
    exit(1);
}
