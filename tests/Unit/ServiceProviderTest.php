<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Db\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(ServiceProvider::class)]
final class ServiceProviderTest extends TestCase {
    public function testDefaultsSerializeToSafeValues(): void { $sp = new ServiceProvider(); $sp->setId(7); $data = $sp->jsonSerialize(); self::assertSame(7, $data['id']); self::assertSame('', $data['spEntityId']); self::assertSame('{}', $data['attributeMapping']); self::assertTrue($data['signAssertions']); self::assertTrue($data['isEnabled']); }
    public function testSettersMarkEveryPersistedFieldUpdated(): void { $sp = new ServiceProvider(); $sp->setSpEntityId('entity'); $sp->setSpName('Service'); $sp->setAcsUrl('https://sp.example.test/acs'); $sp->setSloUrl(''); $sp->setSpCertificate('cert'); $sp->setNameIdFormat('format'); $sp->setAttributeMapping('{}'); $sp->setSignAssertions(false); $sp->setRequireSignedRequests(true); $sp->setIsEnabled(false); self::assertEqualsCanonicalizing(['spEntityId','spName','acsUrl','sloUrl','spCertificate','nameIdFormat','attributeMapping','signAssertions','requireSignedRequests','isEnabled'], $sp->getUpdatedFields()); }
}
