<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\AppInfo\Application; use PHPUnit\Framework\Attributes\CoversNothing; use PHPUnit\Framework\TestCase;
#[CoversNothing]
final class ApplicationTest extends TestCase { public function testApplicationCanBeConstructed():void { $app=new Application(); self::assertSame('saml_provider',Application::APP_ID); self::assertInstanceOf(Application::class,$app); } }
