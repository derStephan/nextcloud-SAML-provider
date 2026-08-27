<?php
declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tests/check-coverage.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$xml = simplexml_load_file($argv[1]);
if ($xml === false) {
    fwrite(STDERR, "Unable to parse Clover report.\n");
    exit(2);
}

/**
 * PHPUnit's text report is calculated from individual production files. Do the
 * same here instead of trusting Clover's project aggregate, whose semantics can
 * vary across PHPUnit/Xdebug releases and may include synthetic totals.
 */
$covered = 0;
$total = 0;
$files = 0;
foreach ($xml->xpath('//file') ?: [] as $file) {
    $path = str_replace('\\', '/', (string)$file['name']);
    if (!preg_match('~(?:^|/)lib/.*\.php$~', $path)) {
        continue;
    }
    $metrics = $file->metrics;
    if ($metrics === null || !isset($metrics['statements'], $metrics['coveredstatements'])) {
        continue;
    }
    $total += (int)$metrics['statements'];
    $covered += (int)$metrics['coveredstatements'];
    $files++;
}

if ($files === 0 || $total === 0) {
    fwrite(STDERR, "No production lib/ file statement metrics were found in Clover report.\n");
    exit(2);
}

$coverage = ($covered / $total) * 100;
printf("Production line coverage (lib/): %.2f%% (%d/%d statements across %d files)\n", $coverage, $covered, $total, $files);
if ($coverage < (float)$argv[2]) {
    fwrite(STDERR, "Coverage threshold not met.\n");
    exit(1);
}
