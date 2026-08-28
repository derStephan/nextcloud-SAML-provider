#!/usr/bin/env php
<?php
declare(strict_types=1);
if ($argc !== 6) { fwrite(STDERR, "Usage: prepare-release version min max php-json auto-bump\n"); exit(2); }
[, $version, $min, $max, $phpJson, $autoBump] = $argv;
$php = json_decode($phpJson, true);
if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || !ctype_digit($min) || !ctype_digit($max) || (int)$min > (int)$max || !is_array($php) || $php === [] || !in_array($autoBump, ['true', 'false'], true)) { fwrite(STDERR, "Invalid compatibility metadata.\n"); exit(2); }
$phpList = implode(', ', $php); $root = dirname(__DIR__);
$info = $root . '/appinfo/info.xml'; $xml = file_get_contents($info);
$xml = preg_replace('/<version>\d+\.\d+\.\d+<\/version>/', "<version>$version</version>", (string)$xml, 1);
$xml = preg_replace('/<nextcloud min-version="\d+"(?: max-version="\d+")?\/>/', "<nextcloud min-version=\"$min\" max-version=\"$max\"/>", (string)$xml, 1);
$xml = preg_replace('/(## Requirements\n\n)(.*?)(\n\n## Getting started)/s', "$1- Tested Nextcloud $min through $max\n- Tested PHP $phpList\n- HTTPS for Nextcloud and connected services\n- PHP OpenSSL and DOM extensions$3", (string)$xml, 1);
if ($xml === null || file_put_contents($info, $xml) === false) exit(1);
$readmeFile=$root.'/README.md'; $readme=file_get_contents($readmeFile);
$readme=preg_replace('/<!-- NEXTCLOUD_COMPATIBILITY:START -->.*?<!-- NEXTCLOUD_COMPATIBILITY:END -->/s', "<!-- NEXTCLOUD_COMPATIBILITY:START -->\n**Tested Nextcloud compatibility:** $min through $max\n<!-- NEXTCLOUD_COMPATIBILITY:END -->", (string)$readme, 1, $a);
$readme=preg_replace('/<!-- PHP_COMPATIBILITY:START -->.*?<!-- PHP_COMPATIBILITY:END -->/s', "<!-- PHP_COMPATIBILITY:START -->\n**Tested PHP compatibility:** $phpList\n<!-- PHP_COMPATIBILITY:END -->", (string)$readme, 1, $b);
if ($readme === null || $a !== 1 || $b !== 1 || file_put_contents($readmeFile,$readme)===false) exit(1);
if ($autoBump === 'true') {
    $change=$root.'/CHANGELOG.md';
    $body=file_get_contents($change);
    $entry="## $version - ".gmdate('Y-m-d')."\n\nAutomated compatibility release after successful Unit, database integration, and Kimai browser E2E tests.\n\nTested Nextcloud compatibility: $min through $max.\nTested PHP compatibility: $phpList.\n\n";
    $body=str_replace("## Unreleased\n", "## Unreleased\n\n".$entry, (string)$body, $count);
    if ($count !== 1 || file_put_contents($change,$body)===false) exit(1);
}
