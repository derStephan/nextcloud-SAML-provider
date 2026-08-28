<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Tests\Unit;

use OCA\SAMLProvider\Service\RawQueryService;
use OCA\SAMLProvider\Tests\Support\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RawQueryService::class)]
final class RawQueryServiceTest extends TestCase {
    public function testReturnsUntouchedRequestQueryString(): void {
        $request = new Request([], 'GET', ['QUERY_STRING' => 'SAMLRequest=raw%2Bvalue&SigAlg=x']);
        self::assertSame('SAMLRequest=raw%2Bvalue&SigAlg=x', (new RawQueryService())->fromRequest($request));
    }

    public function testReturnsEmptyStringWhenQueryIsUnavailableOrInvalid(): void {
        self::assertSame('', (new RawQueryService())->fromRequest(new Request()));
        self::assertSame('', (new RawQueryService())->fromRequest(new Request([], 'GET', ['QUERY_STRING' => ['invalid']])));
    }
}
