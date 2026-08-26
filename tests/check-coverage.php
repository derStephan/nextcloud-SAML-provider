<?php
declare(strict_types=1);
if ($argc !== 3) { fwrite(STDERR, "Usage: php tests/check-coverage.php <clover.xml> <minimum-percent>\n"); exit(2); }
$xml = simplexml_load_file($argv[1]);
if ($xml === false) { fwrite(STDERR, "Unable to parse Clover report.\n"); exit(2); }
$metrics = $xml->project->metrics;
$covered = (int)$metrics['coveredstatements'];
$total = (int)$metrics['statements'];
$coverage = $total === 0 ? 100.0 : ($covered / $total) * 100;
printf("Line coverage: %.2f%% (%d/%d)\n", $coverage, $covered, $total);
if ($coverage < (float)$argv[2]) { fwrite(STDERR, "Coverage threshold not met.\n"); exit(1); }
