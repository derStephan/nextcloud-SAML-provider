<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Service\IdpConfigService;
use OCA\SAMLProvider\Tests\Support\AppConfig;
use OCA\SAMLProvider\Tests\Support\UrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(IdpConfigService::class)]
final class IdpConfigServiceTest extends TestCase {
    private AppConfig $config; private IdpConfigService $service;
    protected function setUp(): void { $this->config = new AppConfig(); $this->service = new IdpConfigService($this->config, new UrlGenerator()); }
    public function testBuildsEndpointsAndUsesDefaultOrganization(): void { self::assertSame('https://cloud.example.test/apps/saml_provider/saml/metadata', $this->service->getEntityId()); self::assertSame('https://cloud.example.test/apps/saml_provider/saml/sso', $this->service->getSsoUrl()); self::assertSame('https://cloud.example.test/apps/saml_provider/saml/slo', $this->service->getSloUrl()); self::assertSame('Nextcloud', $this->service->getOrgName()); }
    public function testStoresOrganizationAndConvertsPemToBase64(): void { $this->service->setOrgName('Example & Co'); self::assertSame('Example & Co', $this->service->getOrgName()); self::assertSame('QUJDRA==', IdpConfigService::pemToBase64("-----BEGIN CERTIFICATE-----\nQUJD RA==\n-----END CERTIFICATE-----")); }
    public function testRejectsInvalidCertificateAndKey(): void { $this->expectException(\InvalidArgumentException::class); $this->service->setCertificate('not a certificate', 'not a key'); }
}
