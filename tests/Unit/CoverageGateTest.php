<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CoverageGateTest extends TestCase {
    public function testCoverageGateSumsOnlyProductionFileMetrics(): void {
        $report = tempnam(sys_get_temp_dir(), 'clover-');
        self::assertNotFalse($report);
        file_put_contents($report, '<?xml version="1.0"?><coverage><project><file name="/app/lib/A.php"><metrics statements="637" coveredstatements="545"/></file><file name="/app/tests/Unit/Test.php"><metrics statements="1" coveredstatements="0"/></file></project></coverage>');
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../check-coverage.php') . ' ' . escapeshellarg($report) . ' 80 2>&1', $output, $status);
        unlink($report);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('85.56%', implode("\n", $output));
        self::assertStringContainsString('545/637', implode("\n", $output));
    }
}
