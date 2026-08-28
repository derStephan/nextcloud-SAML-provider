#!/usr/bin/env php
<?php
declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/prepare-release.php <version> <min-nextcloud> <max-nextcloud>\n");
    exit(2);
}
[, $version, $minVersion, $maxVersion] = $argv;
$notes = trim((string)getenv('RELEASE_NOTES'));
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)
    || !ctype_digit($minVersion)
    || !ctype_digit($maxVersion)
    || (int)$minVersion > (int)$maxVersion
    || $notes === '') {
    fwrite(STDERR, "Invalid manual release metadata.\n");
    exit(2);
}

$root = dirname(__DIR__);
$infoFile = $root . '/appinfo/info.xml';
$xml = file_get_contents($infoFile);
if ($xml === false || !preg_match('/<version>\d+\.\d+\.\d+<\/version>/', $xml)) {
    fwrite(STDERR, "Unable to read semantic version from appinfo/info.xml.\n");
    exit(1);
}
$xml = preg_replace('/<version>\d+\.\d+\.\d+<\/version>/', "<version>{$version}</version>", $xml, 1);
$xml = preg_replace('/<nextcloud min-version="\d+"(?: max-version="\d+")?\/>/', "<nextcloud min-version=\"{$minVersion}\" max-version=\"{$maxVersion}\"/>", $xml, 1);
if ($xml === null || file_put_contents($infoFile, $xml) === false) {
    fwrite(STDERR, "Unable to update appinfo/info.xml.\n");
    exit(1);
}

$changelogFile = $root . '/CHANGELOG.md';
$changelog = file_get_contents($changelogFile);
if ($changelog === false) {
    fwrite(STDERR, "Unable to read CHANGELOG.md.\n");
    exit(1);
}
$entry = "## [{$version}] - " . gmdate('Y-m-d') . "\n\n" . $notes . "\n\n"
    . "Tested Nextcloud compatibility: {$minVersion} through {$maxVersion}.\n\n";
$marker = "## [Unreleased]\n";
$changelog = str_replace($marker, $marker . "\n" . $entry, $changelog, $count);
if ($count !== 1 || file_put_contents($changelogFile, $changelog) === false) {
    fwrite(STDERR, "Unable to update CHANGELOG.md.\n");
    exit(1);
}

$readmeFile = $root . '/README.md';
$readme = file_get_contents($readmeFile);
$compatibility = "**Tested Nextcloud compatibility:** {$minVersion} through {$maxVersion}";
$updatedReadme = $readme === false ? null : preg_replace(
    '/<!-- NEXTCLOUD_COMPATIBILITY:START -->.*?<!-- NEXTCLOUD_COMPATIBILITY:END -->/s',
    "<!-- NEXTCLOUD_COMPATIBILITY:START -->\n{$compatibility}\n<!-- NEXTCLOUD_COMPATIBILITY:END -->",
    $readme,
    1,
    $replacements,
);
if ($updatedReadme === null || $replacements !== 1 || file_put_contents($readmeFile, $updatedReadme) === false) {
    fwrite(STDERR, "Unable to update README compatibility marker.\n");
    exit(1);
}
echo "Prepared manual release {$version} (Nextcloud {$minVersion}-{$maxVersion}).\n";
