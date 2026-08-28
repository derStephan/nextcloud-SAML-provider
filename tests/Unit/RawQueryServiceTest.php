<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Tests\Unit;

use OCA\SAMLProvider\Service\RawQueryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RawQueryService::class)]
final class RawQueryServiceTest extends TestCase {
    public function testReturnsUntouchedServerQueryString(): void {
        $_SERVER['QUERY_STRING'] = 'SAMLRequest=raw%2Bvalue&SigAlg=x';
        self::assertSame('SAMLRequest=raw%2Bvalue&SigAlg=x', (new RawQueryService())->current());
    }
    public function testReturnsEmptyStringWhenQueryIsUnavailableOrInvalid(): void {
        unset($_SERVER['QUERY_STRING']);
        self::assertSame('', (new RawQueryService())->current());
        $_SERVER['QUERY_STRING'] = ['invalid'];
        self::assertSame('', (new RawQueryService())->current());
    }
}
