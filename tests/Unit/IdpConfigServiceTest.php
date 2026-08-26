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
    public function testBuildsEndpointsAndUsesDefaultOrganization(): void { self::assertSame('https://cloud.example.test/apps/saml_provider/saml/metadata', $this->service->getEntityId()); self::assertSame('https://cloud.example.test/apps/saml_provider/saml/sso', $this->service->getSsoUrl()); self::assertSame('https://cloud.example.test/apps/saml_provider/saml/slo', $this->service->getSloUrl()); self::assertSame('Nextcloud', $this->service->getOrgName()); self::assertSame('', $this->service->getCertificate()); self::assertSame('', $this->service->getPrivateKey()); self::assertFalse($this->service->hasCertificate()); }
    public function testStoresOrganizationAndConvertsPemToBase64(): void { $this->service->setOrgName('Example & Co'); self::assertSame('Example & Co', $this->service->getOrgName()); self::assertSame('QUJDRA==', IdpConfigService::pemToBase64("-----BEGIN CERTIFICATE-----\nQUJD RA==\n-----END CERTIFICATE-----")); }
    public function testStoresValidCertificateAndPrivateKeyAsSensitiveValue(): void { [$cert, $key] = $this->newCertificate(); $this->service->setCertificate($cert, $key); self::assertTrue($this->service->hasCertificate()); self::assertSame($cert, $this->service->getCertificate()); self::assertSame($key, $this->service->getPrivateKey()); self::assertSame(IdpConfigService::pemToBase64($cert), $this->service->getCertificateBase64()); self::assertTrue($this->config->writeOptions['idp_private_key']['lazy']); self::assertTrue($this->config->writeOptions['idp_private_key']['sensitive']); }
    public function testRejectsInvalidCertificateAndKey(): void { $this->expectException(\InvalidArgumentException::class); $this->service->setCertificate('not a certificate', 'not a key'); }
    public function testGeneratesAndStoresAUsableSensitiveSigningCertificate(): void {
        $this->service->generateCertificate('cloud.example.test');
        self::assertTrue($this->service->hasCertificate());
        self::assertNotFalse(openssl_x509_read($this->service->getCertificate()));
        self::assertNotFalse(openssl_pkey_get_private($this->service->getPrivateKey()));
        self::assertTrue($this->config->writeOptions['idp_private_key']['lazy']);
        self::assertTrue($this->config->writeOptions['idp_private_key']['sensitive']);
    }
    /** @return array{string,string} */ private function newCertificate(): array { $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); $csr = openssl_csr_new(['commonName' => 'test'], $key); $cert = openssl_csr_sign($csr, null, $key, 1); openssl_x509_export($cert, $certPem); openssl_pkey_export($key, $keyPem); return [$certPem, $keyPem]; }
}
