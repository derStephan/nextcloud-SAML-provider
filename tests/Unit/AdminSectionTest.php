<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Settings\AdminSection;
use OCA\SAMLProvider\Tests\Support\{L10N, UrlGenerator};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(AdminSection::class)]
final class AdminSectionTest extends TestCase {
    public function testSectionMetadataAndIcon(): void {
        $section = new AdminSection(new UrlGenerator(), new L10N());
        self::assertSame('saml_provider', $section->getID());
        self::assertSame('SAML Provider', $section->getName());
        self::assertSame(50, $section->getPriority());
        self::assertSame('/apps/saml_provider/img/app-dark.svg', $section->getIcon());
    }
}
