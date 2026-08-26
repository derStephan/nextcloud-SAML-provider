<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Controller\SamlController;
use OCA\SAMLProvider\Db\{ServiceProvider,ServiceProviderMapper};
use OCA\SAMLProvider\Service\{IdpConfigService,SamlService};
use OCA\SAMLProvider\Tests\Support\{AppConfig,NullLogger,Request,RouteUrlGenerator,Session,UrlGenerator,User,Server};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(SamlController::class)]
#[UsesClass(IdpConfigService::class)]
#[UsesClass(ServiceProvider::class)]
final class SamlControllerTest extends TestCase {
 private ServiceProviderMapper $mapper; private IdpConfigService $idp; private RouteUrlGenerator $urls;
 protected function setUp():void { $this->mapper=new ServiceProviderMapper(); $this->idp=new IdpConfigService(new AppConfig(),new UrlGenerator()); $this->urls=new RouteUrlGenerator(); \OC::$server=new Server(); }
 private function controller(Request $request, Session $session, ?SamlService $service=null):SamlController { $service ??= $this->createMock(SamlService::class); return new SamlController('saml_provider',$request,$service,$this->idp,$session,$this->urls,new NullLogger(),$this->mapper); }
 public function testMetadataIsHiddenUntilCertificateExists():void { self::assertSame(404,$this->controller(new Request(),new Session())->metadata()->status); }
 public function testSsoRejectsMissingRequest():void { self::assertSame(400,$this->controller(new Request(),new Session())->sso()->status); }
 public function testSloLogsOutAndOnlyAcceptsSafeTargets():void { $session=new Session();$session->loggedIn=true;$safe=$this->controller(new Request(['RelayState'=>'/apps/files']),$session)->slo();self::assertTrue($session->loggedOut);self::assertSame('/apps/files',$safe->redirectURL);$unsafe=$this->controller(new Request(['RelayState'=>'https://evil.test']),new Session())->slo();self::assertSame('/',$unsafe->redirectURL); }
 public function testIdpInitiatedRedirectsAnonymousUserToLogin():void { $response=$this->controller(new Request(),new Session())->idpInitiated(4);self::assertSame(302,$response->status);self::assertStringContainsString('core.login.showLoginForm',$response->redirectURL); }
 public function testIdpInitiatedReturnsNotFoundForUnknownService():void {
  $service=$this->createMock(SamlService::class);
  $service->method('resolveServiceProviderById')->willThrowException(new \RuntimeException('not found'));
  $session=new Session(new User()); $session->loggedIn=true;
  self::assertSame(404,$this->controller(new Request(),$session,$service)->idpInitiated(9)->status);
 public function testMetadataReturnsDownloadWhenCertificateExists():void { [$cert,$key]=$this->certificate(); $this->idp->setCertificate($cert,$key); $service=$this->createMock(SamlService::class); $service->method('buildMetadataXml')->willReturn('<xml/>'); $response=$this->controller(new Request(),new Session(),$service)->metadata(); self::assertSame('metadata.xml',$response->filename); self::assertSame('<xml/>',$response->data); }
 public function testSloAcceptsRegisteredServiceProviderHost():void { $sp=new ServiceProvider(); $sp->setAcsUrl('https://sp.example.test/acs'); $this->mapper->enabled=[$sp]; $response=$this->controller(new Request(['RelayState'=>'https://sp.example.test/after']),new Session())->slo(); self::assertSame('https://sp.example.test/after',$response->redirectURL); }
 public function testIdpInitiatedBuildsPostResponseForLoggedInUser():void { $sp=new ServiceProvider(); $sp->setAcsUrl('https://sp.example.test/acs'); $service=$this->createMock(SamlService::class); $service->method('resolveServiceProviderById')->willReturn($sp); $service->method('buildResponse')->willReturn('encoded-response'); $session=new Session(new User()); $session->loggedIn=true; $response=$this->controller(new Request(),$session,$service)->idpInitiated(1); self::assertSame('post_response',$response->templateName); self::assertSame('https://sp.example.test/acs',$response->params['acsUrl']); }
 private function certificate():array { $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); $csr=openssl_csr_new(['commonName'=>'test'],$key); $cert=openssl_csr_sign($csr,null,$key,1); openssl_x509_export($cert,$pem); openssl_pkey_export($key,$private); return [$pem,$private]; }

}
}
