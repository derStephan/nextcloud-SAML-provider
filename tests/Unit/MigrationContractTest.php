<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
#[CoversNothing]
final class MigrationContractTest extends TestCase {
    public function testServiceProviderMigrationDefinesTheCompletePersistenceContract(): void {
        $source = file_get_contents(__DIR__ . '/../../lib/Migration/Version0001Date20260826000000.php');
        self::assertNotFalse($source);
        foreach (["saml_provider_sp", "sp_entity_id", "sp_name", "acs_url", "slo_url", "sp_certificate", "name_id_format", "attribute_mapping", "sign_assertions", "require_signed_requests", "is_enabled"] as $required) {
            self::assertStringContainsString($required, $source);
        }
        self::assertStringContainsString("addUniqueIndex(['sp_entity_id']", $source);
        self::assertStringContainsString("hasTable('saml_provider_sp')", $source);
    }
}
