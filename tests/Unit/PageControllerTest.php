<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Controller\PageController; use OCA\SAMLProvider\Db\{ServiceProvider,ServiceProviderMapper}; use OCA\SAMLProvider\Tests\Support\{TestServiceProviderMapper,InitialState,Request,RouteUrlGenerator}; use PHPUnit\Framework\Attributes\CoversClass; use PHPUnit\Framework\Attributes\UsesClass; use PHPUnit\Framework\TestCase;
#[CoversClass(PageController::class)]
#[UsesClass(ServiceProvider::class)]
final class PageControllerTest extends TestCase { public function testPublishesEnabledServices():void { $sp=new ServiceProvider();$sp->setId(7);$sp->setSpName('Kimai');$m=new TestServiceProviderMapper();$m->enabled=[$sp];$state=new InitialState();$r=(new PageController('saml_provider',new Request(),$m,$state,new RouteUrlGenerator()))->index();self::assertSame('page/index',$r->templateName);self::assertSame('Kimai',$state->values['serviceProviders'][0]['name']); } }
