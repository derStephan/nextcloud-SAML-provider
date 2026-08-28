<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Tests\Unit;

use OCA\SAMLProvider\Controller\SamlController;
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Service\IdpConfigService;
use OCA\SAMLProvider\Service\SamlService;
use OCA\SAMLProvider\Service\RawQueryService;
use OCA\SAMLProvider\Tests\Support\AppConfig;
use OCA\SAMLProvider\Tests\Support\NullLogger;
use OCA\SAMLProvider\Tests\Support\Request;
use OCA\SAMLProvider\Tests\Support\RouteUrlGenerator;
use OCA\SAMLProvider\Tests\Support\Session;
use OCA\SAMLProvider\Tests\Support\UrlGenerator;
use OCA\SAMLProvider\Tests\Support\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SamlController::class)]
#[UsesClass(IdpConfigService::class)]
#[UsesClass(ServiceProvider::class)]
#[UsesClass(RawQueryService::class)]
final class SamlControllerTest extends TestCase {
    private IdpConfigService $idp;
    private RouteUrlGenerator $urls;

    protected function setUp(): void {
        $this->idp = new IdpConfigService(new AppConfig(), new UrlGenerator());
        $this->urls = new RouteUrlGenerator();
    }

    private function controller(Request $request, Session $session, ?SamlService $service = null): SamlController {
        $service ??= $this->createMock(SamlService::class);
        return new SamlController('saml_provider', $request, $service, $this->idp, $session, $this->urls, new NullLogger(), new RawQueryService());
    }

    public function testMetadataIsHiddenUntilCertificateExists(): void {
        self::assertSame(404, $this->controller(new Request(), new Session())->metadata()->status);
    }

    public function testMetadataReturnsDownloadWhenCertificateExists(): void {
        $this->idp->generateCertificate('cloud.example.test');
        $service = $this->createMock(SamlService::class);
        $service->method('buildMetadataXml')->willReturn('<xml/>');
        $response = $this->controller(new Request(), new Session(), $service)->metadata();
        self::assertSame('metadata.xml', $response->filename);
        self::assertSame('<xml/>', $response->data);
    }

    public function testSsoRejectsMissingRequest(): void {
        self::assertSame(400, $this->controller(new Request(), new Session())->sso()->status);
    }

    public function testSsoBuildsPostResponseForLoggedInUser(): void {
        $sp = $this->provider();
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_request', 'issuer' => 'https://sp.example.test/meta', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($sp);
        $service->expects(self::once())->method('enforceRequestSignature');
        $service->method('buildResponse')->willReturn('encoded-response');
        $session = new Session(new User());
        $session->loggedIn = true;
        $response = $this->controller(new Request(['SAMLRequest' => 'encoded-request', 'RelayState' => '/after'], 'GET'), $session, $service)->sso();
        self::assertSame('post_response', $response->templateName);
        self::assertSame('https://sp.example.test/acs', $response->params['acsUrl']);
        self::assertSame('/after', $response->params['relayState']);
    }

    public function testSamlPostResponseAllowsTheAcsHostAndPortForNextcloudCsp(): void {
        $sp = $this->provider('http://sp.example.test:8001/acs');
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_id', 'issuer' => 'sp', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($sp);
        $service->method('buildResponse')->willReturn('encoded-response');
        $session = new Session(new User());
        $session->loggedIn = true;
        $response = $this->controller(new Request(['SAMLRequest' => 'request']), $session, $service)->sso();
        self::assertSame(['sp.example.test:8001'], $response->contentSecurityPolicy->domains);
    }

    public function testSsoRedirectsAnonymousUserAfterValidRequest(): void {
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_id', 'issuer' => 'sp', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($this->provider());
        $response = $this->controller(new Request(['SAMLRequest' => 'request'], 'GET'), new Session(), $service)->sso();
        self::assertSame(302, $response->status);
        self::assertStringContainsString('core.login.showLoginForm', $response->redirectURL);
    }

    public function testSsoRejectsParserOrPolicyFailureWithoutLeakingDetails(): void {
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willThrowException(new \InvalidArgumentException('attacker-controlled detail'));
        $response = $this->controller(new Request(['SAMLRequest' => 'malformed'], 'POST'), new Session(), $service)->sso();
        self::assertSame(400, $response->status);
    }

    public function testSsoRedirectPreservesRawQueryOnlyAfterRequestValidation(): void {
        $service = $this->validService($this->provider());
        $request = new Request(['SAMLRequest' => 'decoded value'], 'GET', ['QUERY_STRING' => 'SAMLRequest=decoded%20value&RelayState=next']);
        $response = $this->controller($request, new Session(), $service)->sso();
        self::assertSame(302, $response->status);
        self::assertStringContainsString('redirect_url=%2Fsaml_provider.saml.sso%3FSAMLRequest%3Ddecoded%2520value%26RelayState%3Dnext', $response->redirectURL);
    }

    public function testSsoRejectsLoggedInSessionWithoutUserObject(): void {
        $service = $this->validService($this->provider());
        $session = new Session();
        $session->loggedIn = true;
        self::assertSame(401, $this->controller(new Request(['SAMLRequest' => 'request']), $session, $service)->sso()->status);
    }

    public function testIdpInitiatedRedirectsAnonymousAndRejectsUnknownService(): void {
        $anonymous = $this->controller(new Request(), new Session())->idpInitiated(42);
        self::assertSame(302, $anonymous->status);
        self::assertStringContainsString('core.login.showLoginForm', $anonymous->redirectURL);

        $service = $this->createMock(SamlService::class);
        $service->method('resolveServiceProviderById')->willThrowException(new \RuntimeException('not found'));
        $session = new Session(new User());
        $session->loggedIn = true;
        self::assertSame(404, $this->controller(new Request(), $session, $service)->idpInitiated(42)->status);
    }

    public function testConfirmedIdpInitiatedLoginRejectsAnonymousUnknownAndMissingUserSessions(): void {
        self::assertSame(401, $this->controller(new Request([], 'POST'), new Session())->confirmIdpInitiated(1)->status);

        $unknown = $this->createMock(SamlService::class);
        $unknown->method('resolveServiceProviderById')->willThrowException(new \RuntimeException('not found'));
        $loggedIn = new Session(new User());
        $loggedIn->loggedIn = true;
        self::assertSame(404, $this->controller(new Request([], 'POST'), $loggedIn, $unknown)->confirmIdpInitiated(1)->status);

        $service = $this->createMock(SamlService::class);
        $service->method('resolveServiceProviderById')->willReturn($this->provider());
        $missingUser = new Session();
        $missingUser->loggedIn = true;
        self::assertSame(401, $this->controller(new Request([], 'POST'), $missingUser, $service)->confirmIdpInitiated(1)->status);
    }

    public function testIdpInitiatedGetShowsConfirmationInsteadOfCreatingAssertion(): void {
        $service = $this->createMock(SamlService::class);
        $service->method('resolveServiceProviderById')->willReturn($this->provider());
        $service->expects(self::never())->method('buildResponse');
        $session = new Session(new User());
        $session->loggedIn = true;
        $response = $this->controller(new Request(), $session, $service)->idpInitiated(1);
        self::assertSame('page/confirm_login', $response->templateName);
        self::assertSame(1, $response->params['spId']);
    }

    public function testConfirmedIdpInitiatedLoginCreatesPostResponse(): void {
        $service = $this->createMock(SamlService::class);
        $service->method('resolveServiceProviderById')->willReturn($this->provider());
        $service->method('buildResponse')->willReturn('encoded-response');
        $session = new Session(new User());
        $session->loggedIn = true;
        $response = $this->controller(new Request([], 'POST'), $session, $service)->confirmIdpInitiated(1);
        self::assertSame('post_response', $response->templateName);
        self::assertSame('https://sp.example.test/acs', $response->params['acsUrl']);
    }

    private function validService(ServiceProvider $sp): SamlService {
        $service = $this->createMock(SamlService::class);
        $service->method('parseAuthnRequest')->willReturn(['id' => '_id', 'issuer' => 'sp', 'acsUrl' => null, 'nameIdPolicy' => null, 'rawXml' => '<request/>']);
        $service->method('resolveServiceProvider')->willReturn($sp);
        return $service;
    }

    private function provider(string $acsUrl = 'https://sp.example.test/acs'): ServiceProvider {
        $sp = new ServiceProvider();
        $sp->setSpEntityId('https://sp.example.test/meta');
        $sp->setSpName('Example service');
        $sp->setAcsUrl($acsUrl);
        $sp->setIsEnabled(true);
        return $sp;
    }
}
