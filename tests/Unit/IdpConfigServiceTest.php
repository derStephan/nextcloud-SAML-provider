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
    public function testBuildsEndpoints(): void { self::assertSame('https://cloud.example.test/apps/saml_provider/saml/metadata', $this->service->getEntityId()); self::assertSame('https://cloud.example.test/apps/saml_provider/saml/sso', $this->service->getSsoUrl()); self::assertSame('', $this->service->getCertificate()); self::assertSame('', $this->service->getPrivateKey()); self::assertFalse($this->service->hasCertificate()); }
    public function testConvertsPemToBase64(): void { self::assertSame('QUJDRA==', IdpConfigService::pemToBase64("-----BEGIN CERTIFICATE-----\nQUJD RA==\n-----END CERTIFICATE-----")); }


    public function testGeneratesAndStoresAUsableSensitiveSigningCertificate(): void {
        $this->service->generateCertificate('cloud.example.test');
        self::assertTrue($this->service->hasCertificate());
        self::assertNotFalse(openssl_x509_read($this->service->getCertificate()));
        self::assertNotFalse(openssl_pkey_get_private($this->service->getPrivateKey()));
        self::assertTrue($this->config->writeOptions['idp_private_key']['lazy']);
        self::assertTrue($this->config->writeOptions['idp_private_key']['sensitive']);
        self::assertNotSame('', $this->service->getPersistentNameIdPepper());
        self::assertTrue($this->config->writeOptions['persistent_nameid_pepper']['sensitive']);
    }
    public function testPersistentNameIdPepperIsNotCreatedInReadPath(): void {
        $this->expectException(\RuntimeException::class);
        $this->service->getPersistentNameIdPepper();
    }
}
