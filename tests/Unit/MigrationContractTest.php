<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
/**
 * Keeps migration intent reviewable in unit CI. Real DBAL DDL, mapper CRUD, and the Version0002 remove/reapply/idempotency
 * upgrade path are enforced by integration contracts on SQLite, MariaDB, and
 * PostgreSQL in the integration workflow.
 */
#[CoversNothing]
final class MigrationContractTest extends TestCase {
    public function testFreshSchemaUsesPortableColumnsAndIndexes(): void {
        $source = (string)file_get_contents(__DIR__ . '/../../lib/Migration/Version0001Date20260826000000.php');
        self::assertStringContainsString("'length' => 255", $source);
        self::assertStringContainsString("addUniqueIndex(['sp_entity_id']", $source);
        self::assertStringContainsString("addIndex(['is_enabled']", $source);
        self::assertStringNotContainsString("'text', ['notnull' => true, 'default'", $source);
        self::assertStringNotContainsString("'slo_url'", $source);
    }
    public function testUpgradeMigrationIsAdditiveAndIdempotent(): void {
        $source = (string)file_get_contents(__DIR__ . '/../../lib/Migration/Version0002Date20260828000000.php');
        self::assertStringContainsString("hasTable('saml_provider_sp')", $source);
        self::assertStringContainsString("hasIndex('saml_provider_sp_enabled')", $source);
        self::assertStringContainsString("addIndex(['is_enabled']", $source);
        self::assertStringNotContainsString('drop', strtolower($source));
    }
}
