<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Db\{ServiceProvider,ServiceProviderMapper};
use OCA\SAMLProvider\Service\IdpConfigService;
use OCA\SAMLProvider\Settings\Admin;
use OCA\SAMLProvider\Tests\Support\{AppConfig,InitialState,UrlGenerator};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(Admin::class)]
#[UsesClass(IdpConfigService::class)]
#[UsesClass(ServiceProvider::class)]
final class AdminSettingsTest extends TestCase {
 public function testPublishesIdpAndServiceProviderInitialState():void {
  $config=new AppConfig(); $idp=new IdpConfigService($config,new UrlGenerator()); $mapper=new ServiceProviderMapper();
  $sp=new ServiceProvider(); $sp->setSpName('Kimai'); $mapper->rows=[$sp]; $state=new InitialState();
  $admin=new Admin($idp,$mapper,$state); $response=$admin->getForm();
  self::assertSame('settings/index',$response->templateName); self::assertSame('saml_provider',$admin->getSection()); self::assertSame(50,$admin->getPriority());
  self::assertArrayHasKey('idp',$state->values); self::assertSame('Kimai',$state->values['serviceProviders'][0]['spName']); self::assertArrayHasKey('help',$state->values);
 }
}
