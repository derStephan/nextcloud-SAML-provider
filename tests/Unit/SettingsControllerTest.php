<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Controller\SettingsController;
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCA\SAMLProvider\Service\IdpConfigService;
use OCA\SAMLProvider\Tests\Support\{TestServiceProviderMapper,AppConfig,L10N,NullLogger,Request,UrlGenerator};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(SettingsController::class)]
#[UsesClass(IdpConfigService::class)]
#[UsesClass(ServiceProvider::class)]
final class SettingsControllerTest extends TestCase {
 private ServiceProviderMapper $mapper; private SettingsController $controller;
 protected function setUp():void { $this->mapper=new TestServiceProviderMapper(); $idp=new IdpConfigService(new AppConfig(),new UrlGenerator()); $this->controller=new SettingsController('saml_provider',new Request(),$this->mapper,$idp,new L10N(),new NullLogger()); }
 public function testCreatesValidProvider():void { $r=$this->controller->createSp('https://sp.test/meta','SP','https://sp.test/acs'); self::assertSame(201,$r->status); self::assertSame('https://sp.test/acs',$r->data['acsUrl']); }
 public function testRejectsUnsafeProviderInputs():void { foreach ([['','https://sp.test/acs'],['x','javascript:alert(1)'],['x','http://sp.test/acs']] as [$id,$acs]) { $r=$this->controller->createSp($id,'SP',$acs); self::assertSame(400,$r->status); } }
 public function testUpdateRevalidatesAcsAndSigningCertificate():void { $sp=new ServiceProvider(); $sp->setSpEntityId('https://sp.test/meta'); $sp->setAcsUrl('https://sp.test/acs'); $sp->setNameIdFormat('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'); $sp->setAttributeMapping('{}'); $this->mapper->byId=$sp; self::assertSame(400,$this->controller->updateSp(1,['acsUrl'=>'javascript:bad'])->status); self::assertSame(400,$this->controller->updateSp(1,['requireSignedRequests'=>true])->status); }
 public function testDeleteMissingAndExistingProvider():void { self::assertSame(404,$this->controller->deleteSp(1)->status); $sp=new ServiceProvider(); $this->mapper->byId=$sp; self::assertSame(204,$this->controller->deleteSp(1)->status); }
 public function testRejectsDuplicateEntityId():void { $existing=new ServiceProvider(); $this->mapper->byEntityId=$existing; self::assertSame(409,$this->controller->createSp('https://sp.test/meta','SP','https://sp.test/acs')->status); }
 public function testUpdatesValidProviderAndNormalizesEmptyOptionalValues():void { $sp=new ServiceProvider(); $sp->setSpEntityId('https://sp.test/meta'); $sp->setAcsUrl('https://sp.test/acs'); $sp->setNameIdFormat('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'); $sp->setAttributeMapping('{}'); $this->mapper->byId=$sp; $r=$this->controller->updateSp(1,['spName'=>'Updated','attributeMapping'=>'{}','isEnabled'=>false]); self::assertSame(200,$r->status); self::assertSame('Updated',$r->data['spName']); self::assertFalse($r->data['isEnabled']); }
 public function testGeneratesCertificate():void { $config=new AppConfig(); $idp=new IdpConfigService($config,new UrlGenerator()); $controller=new SettingsController('saml_provider',new Request(),$this->mapper,$idp,new L10N(),new NullLogger()); $generated=$controller->generateCert(); self::assertSame(200,$generated->status); self::assertNotSame('',$generated->data['certificate']); }

 public function testRethrowsUnexpectedMapperUpdateFailure():void {
   $sp=new ServiceProvider(); $sp->setId(1); $sp->setSpEntityId('https://sp.test/meta'); $sp->setAcsUrl('https://sp.test/acs'); $sp->setNameIdFormat('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'); $sp->setAttributeMapping('{}');
   $this->mapper->byId=$sp; $this->mapper->failUpdate=true;
   $this->expectException(\RuntimeException::class);
   $this->controller->updateSp(1,['spName'=>'Changed']);
 }

}
