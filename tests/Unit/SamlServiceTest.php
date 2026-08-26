<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCA\SAMLProvider\Service\IdpConfigService;
use OCA\SAMLProvider\Service\SamlService;
use OCA\SAMLProvider\Service\SignatureService;
use OCA\SAMLProvider\Tests\Support\AppConfig;
use OCA\SAMLProvider\Tests\Support\NullLogger;
use OCA\SAMLProvider\Tests\Support\UrlGenerator;
use OCA\SAMLProvider\Tests\Support\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(SamlService::class)]
#[UsesClass(IdpConfigService::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(SignatureService::class)]
final class SamlServiceTest extends TestCase {
    private ServiceProviderMapper $mapper; private IdpConfigService $idp; private SamlService $service;
    protected function setUp(): void {
        $this->mapper = new ServiceProviderMapper();
        $this->idp = new IdpConfigService(new AppConfig(), new UrlGenerator());
        [$cert, $key] = $this->newCertificate(); $this->idp->setCertificate($cert, $key);
        $this->service = new SamlService($this->idp, $this->mapper, new SignatureService(), new NullLogger());
    }
    public function testMetadataContainsEndpointsAndSigningRequirement(): void {
        $this->mapper->requiresSignedRequests = true;
        $xml = $this->service->buildMetadataXml();
        self::assertStringContainsString('WantAuthnRequestsSigned="true"', $xml);
        self::assertStringContainsString('https://cloud.example.test/apps/saml_provider/saml/sso', $xml);
        self::assertStringContainsString('<ds:X509Certificate>', $xml);
    }
    public function testParsesRedirectAndPostAuthnRequests(): void {
        $xml = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="_request" AssertionConsumerServiceURL="https://sp.example.test/acs"><saml:Issuer xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">https://sp.example.test/metadata</saml:Issuer><samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress"/></samlp:AuthnRequest>';
        $post = $this->service->parseAuthnRequest(base64_encode($xml), 'post');
        $redirect = $this->service->parseAuthnRequest(base64_encode(gzdeflate($xml)), 'redirect');
        foreach ([$post, $redirect] as $parsed) { self::assertSame('_request', $parsed['id']); self::assertSame('https://sp.example.test/metadata', $parsed['issuer']); self::assertSame('https://sp.example.test/acs', $parsed['acsUrl']); }
    }
    public function testRejectsInvalidAuthnRequestInputs(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->parseAuthnRequest('not-base64', 'post');
    }
    public function testRejectsOversizedAuthnRequest(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->parseAuthnRequest(base64_encode(str_repeat('A', 1048577)), 'post');
    }
    public function testRejectsDtdEntityAuthnRequest(): void {
        $xml = '<!DOCTYPE x [<!ENTITY payload "blocked">]><samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="_x"><Issuer>&payload;</Issuer></samlp:AuthnRequest>';
        $this->expectException(\InvalidArgumentException::class);
        $this->service->parseAuthnRequest(base64_encode($xml), 'post');
    }
    public function testResolvesOnlyEnabledServiceProviders(): void {
        $sp = $this->provider(); $this->mapper->byEntityId = $sp; $this->mapper->byId = $sp;
        self::assertSame($sp, $this->service->resolveServiceProvider('https://sp.example.test/metadata'));
        self::assertSame($sp, $this->service->resolveServiceProviderById(1));
        $sp->setIsEnabled(false);
        $this->expectException(\RuntimeException::class); $this->service->resolveServiceProvider('https://sp.example.test/metadata');
    }
    public function testBuildsSignedResponseWithExpectedSubjectAndAttributes(): void {
        $sp = $this->provider(); $response = base64_decode($this->service->buildResponse($sp, new User('alice', 'alice@example.test', 'Alice'), '_request', 'https://attacker.example/acs'), true);
        self::assertNotFalse($response);
        self::assertSame(0, substr_count($response, '<?xml'), 'Nested SAML content must not contain an XML declaration.');
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($response, LIBXML_NONET));
        self::assertStringContainsString('Destination="https://sp.example.test/acs"', $response);
        self::assertStringContainsString('InResponseTo="_request"', $response);
        self::assertStringContainsString('<saml2:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">alice@example.test</saml2:NameID>', $response);
        self::assertStringContainsString('Name="mail"', $response);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($response));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        self::assertSame(2, $xpath->query('//ds:Signature')->length);
    }
    private function provider(): ServiceProvider { $sp = new ServiceProvider(); $sp->setId(1); $sp->setSpEntityId('https://sp.example.test/metadata'); $sp->setSpName('Example SP'); $sp->setAcsUrl('https://sp.example.test/acs'); $sp->setSignAssertions(true); $sp->setIsEnabled(true); return $sp; }
    /** @return array{string,string} */ private function newCertificate(): array { $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); self::assertNotFalse($key); $csr = openssl_csr_new(['commonName' => 'test'], $key); $cert = openssl_csr_sign($csr, null, $key, 1); openssl_x509_export($cert, $certPem); openssl_pkey_export($key, $keyPem); return [$certPem, $keyPem]; }
}
