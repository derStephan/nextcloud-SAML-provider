<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Tests\Unit;

use OCA\SAMLProvider\Controller\SamlController;
use OCA\SAMLProvider\Db\{ServiceProvider, ServiceProviderMapper};
use OCA\SAMLProvider\Service\{IdpConfigService, SamlService};
use OCA\SAMLProvider\Tests\Support\{AppConfig, NullLogger, Request, RouteUrlGenerator, Server, Session, UrlGenerator, User};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SamlController::class)]
#[UsesClass(IdpConfigService::class)]
#[UsesClass(ServiceProvider::class)]
final class SamlControllerTest extends TestCase {
    private ServiceProviderMapper $mapper;
    private IdpConfigService $idp;
    private RouteUrlGenerator $urls;

    protected function setUp(): void {
        $this->mapper = new ServiceProviderMapper();
        $this->idp = new IdpConfigService(new AppConfig(), new UrlGenerator());
        $this->urls = new RouteUrlGenerator();
        \OC::$server = new Server();
    }

    private function controller(Request $request, Session $session, ?SamlService $service = null): SamlController {
        $service ??= $this->createMock(SamlService::class);
        return new SamlController('saml_provider', $request, $service, $this->idp, $session, $this->urls, new NullLogger(), $this->mapper);
    }

    public function testMetadataIsHiddenUntilCertificateExists(): void {
        self::assertSame(404, $this->controller(new Request(), new Session())->metadata()->status);
    }

    public function testSsoRejectsMissingRequest(): void {
        self::assertSame(400, $this->controller(new Request(), new Session())->sso()->status);
    }

    public function testSloLogsOutAndOnlyAcceptsSafeTargets(): void {
        $session = new Session();
        $session->loggedIn = true;
        $safe = $this->controller(new Request(['RelayState' => '/apps/files']), $session)->slo();
        self::assertTrue($session->loggedOut);
        self::assertSame('/apps/files', $safe->redirectURL);
        $unsafe = $this->controller(new Request(['RelayState' => 'https://evil.test']), new Session())->slo();
        self::assertSame('/', $unsafe->redirectURL);
    }

    public function testIdpInitiatedRedirectsAnonymousUserToLogin(): void {
        $response = $this->controller(new Request(), new Session())->idpInitiated(4);
        self::assertSame(302, $response->status);
        self::assertStringContainsString('core.login.showLoginForm', $response->redirectURL);
    }

    public function testIdpInitiatedReturnsNotFoundForUnknownService(): void {
        $service = $this->createMock(SamlService::class);
        $service->method('resolveServiceProviderById')->willThrowException(new \RuntimeException('not found'));
        $session = new Session(new User());
        $session->loggedIn = true;
        self::assertSame(404, $this->controller(new Request(), $session, $service)->idpInitiated(9)->status);
    }

    public function testMetadataReturnsDownloadWhenCertificateExists(): void {
        [$cert, $key] = $this->certificate();
        $this->idp->setCertificate($cert, $key);
        $service = $this->createMock(SamlService::class);
        $service->method('buildMetadataXml')->willReturn('<xml/>');
        $response = $this->controller(new Request(), new Session(), $service)->metadata();
        self::assertSame('metadata.xml', $response->filename);
        self::assertSame('<xml/>', $response->data);
    }

    public function testSloAcceptsRegisteredServiceProviderHost(): void {
        $sp = new ServiceProvider();
        $sp->setAcsUrl('https://sp.example.test/acs');
        $this->mapper->enabled = [$sp];
        $response = $this->controller(new Request(['RelayState' => 'https://sp.example.test/after']), new Session())->slo();
        self::assertSame('https://sp.example.test/after', $response->redirectURL);
    }

    public function testSloRequiresExactRegisteredOrigin(): void {
        $sp = new ServiceProvider();
        $sp->setAcsUrl('https://sp.example.test/acs');
        $this->mapper->enabled = [$sp];
        $controller = $this->controller(new Request(['RelayState' => 'https://sp.example.test:443/after']), new Session());
        self::assertSame('https://sp.example.test:443/after', $controller->slo()->redirectURL);
        self::assertSame('/', $this->controller(new Request(['RelayState' => 'http://sp.example.test/after']), new Session())->slo()->redirectURL);
        self::assertSame('/', $this->controller(new Request(['RelayState' => 'https://sp.example.test:8443/after']), new Session())->slo()->redirectURL);
        self::assertSame('/', $this->controller(new Request(['RelayState' => '/\\evil.test']), new Session())->slo()->redirectURL);
    }

    public function testSsoBuildsPostResponseForLoggedInUser(): void {
        $sp = new ServiceProvider();
        $sp->setAcsUrl('https://sp.example.test/acs');
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_request', 'issuer' => 'https://sp.example.test/meta', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($sp);
        $_SERVER['QUERY_STRING'] = 'SAMLRequest=encoded-request&RelayState=https%3A%2F%2Fsp.example.test';
        $service->expects(self::once())->method('enforceRequestSignature')->with(
            self::isType('array'), 'redirect', self::isType('array'), $sp,
            'SAMLRequest=encoded-request&RelayState=https%3A%2F%2Fsp.example.test'
        );
        $service->method('buildResponse')->willReturn('encoded-response');
        $session = new Session(new User());
        $session->loggedIn = true;
        $request = new Request(['SAMLRequest' => 'encoded-request', 'RelayState' => '/after'], 'GET', ['QUERY_STRING' => 'SAMLRequest=encoded-request']);
        $response = $this->controller($request, $session, $service)->sso();
        self::assertSame('post_response', $response->templateName);
        self::assertSame('https://sp.example.test/acs', $response->params['acsUrl']);
        self::assertSame('/after', $response->params['relayState']);
    }

    public function testSamlPostResponseAllowsTheAcsHostForNextcloudCsp(): void {
        $sp = new ServiceProvider();
        $sp->setAcsUrl('http://sp.example.test:8001/acs');
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_id', 'issuer' => 'sp', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($sp);
        $service->method('buildResponse')->willReturn('encoded-response');
        $session = new Session(new User());
        $session->loggedIn = true;
        $response = $this->controller(new Request(['SAMLRequest' => 'request']), $session, $service)->sso();
        self::assertSame(['sp.example.test'], $response->contentSecurityPolicy->domains);
    }

    public function testSsoRedirectsAnonymousUserAfterValidRequest(): void {
        $sp = new ServiceProvider();
        $sp->setAcsUrl('https://sp.example.test/acs');
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_id', 'issuer' => 'sp', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($sp);
        $request = new Request(['SAMLRequest' => 'request'], 'GET', ['QUERY_STRING' => 'SAMLRequest=request']);
        $response = $this->controller($request, new Session(), $service)->sso();
        self::assertSame(302, $response->status);
        self::assertStringContainsString('core.login.showLoginForm', $response->redirectURL);
    }

    public function testSsoReturnsUnauthorizedWhenSessionHasNoUser(): void {
        $sp = new ServiceProvider();
        $sp->setAcsUrl('https://sp.example.test/acs');
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_id', 'issuer' => 'sp', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($sp);
        $session = new Session(null);
        $session->loggedIn = true;
        self::assertSame(401, $this->controller(new Request(['SAMLRequest' => 'request']), $session, $service)->sso()->status);
    }

    public function testIdpInitiatedBuildsPostResponseForLoggedInUser(): void {
        $sp = new ServiceProvider();
        $sp->setAcsUrl('https://sp.example.test/acs');
        $service = $this->createMock(SamlService::class);
        $service->method('resolveServiceProviderById')->willReturn($sp);
        $service->method('buildResponse')->willReturn('encoded-response');
        $session = new Session(new User());
        $session->loggedIn = true;
        $response = $this->controller(new Request(), $session, $service)->idpInitiated(1);
        self::assertSame('post_response', $response->templateName);
        self::assertSame('https://sp.example.test/acs', $response->params['acsUrl']);
    }

    /** @return array{string, string} */
    private function certificate(): array {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'test'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($cert, $certificate);
        openssl_pkey_export($key, $privateKey);
        return [$certificate, $privateKey];
    }
}
