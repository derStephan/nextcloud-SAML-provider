#!/usr/bin/env php
<?php
declare(strict_types=1);
if ($argc !== 4) { fwrite(STDERR, "Usage: php scripts/prepare-release.php <min-nextcloud> <max-nextcloud> <reason>\n"); exit(2); }
[$script, $minVersion, $maxVersion, $reason] = $argv;
if (!ctype_digit($minVersion) || !ctype_digit($maxVersion) || (int)$minVersion > (int)$maxVersion) { fwrite(STDERR, "Invalid Nextcloud compatibility range.\n"); exit(2); }
$infoFile = __DIR__ . '/../appinfo/info.xml'; $xml = file_get_contents($infoFile);
if ($xml === false || !preg_match('/<version>(\d+)\.(\d+)\.(\d+)<\/version>/', $xml, $matches)) { fwrite(STDERR, "Unable to read semantic version from appinfo/info.xml.\n"); exit(1); }
$newVersion = "{$matches[1]}.{$matches[2]}." . ((int)$matches[3] + 1);
$xml = preg_replace('/<version>\d+\.\d+\.\d+<\/version>/', "<version>{$newVersion}</version>", $xml, 1);
$xml = preg_replace('/<nextcloud min-version="\d+"(?: max-version="\d+")?\/>/', "<nextcloud min-version=\"{$minVersion}\" max-version=\"{$maxVersion}\"/>", $xml, 1);
if ($xml === null || file_put_contents($infoFile, $xml) === false) { fwrite(STDERR, "Unable to update appinfo/info.xml.\n"); exit(1); }
$changelogFile = __DIR__ . '/../CHANGELOG.md'; $changelog = file_get_contents($changelogFile);
if ($changelog === false) { fwrite(STDERR, "Unable to read CHANGELOG.md.\n"); exit(1); }
$entry = "## [{$newVersion}] - " . gmdate('Y-m-d') . "\n\n### Changed\n\n- Automated release after successful quality checks.\n- Tested stable Nextcloud compatibility range: {$minVersion} through {$maxVersion}.\n- Release trigger: {$reason}.\n\n";
$marker = "## [Unreleased]\n";
$changelog = str_replace($marker, $marker . "\n" . $entry, $changelog, $count);
if ($count !== 1 || file_put_contents($changelogFile, $changelog) === false) { fwrite(STDERR, "Unable to update CHANGELOG.md.\n"); exit(1); }
$readmeFile = __DIR__ . '/../README.md';
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
file_put_contents(getcwd() . '/release-version.txt', $newVersion . PHP_EOL);
echo "Prepared release {$newVersion} (Nextcloud {$minVersion}-{$maxVersion}).\n";
