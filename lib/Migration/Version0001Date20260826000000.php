<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Creates the Service Provider configuration table for supported Nextcloud versions. */
class Version0001Date20260826000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('saml_provider_sp')) {
            return null;
        }
        $table = $schema->createTable('saml_provider_sp');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('sp_entity_id', 'string', ['notnull' => true, 'length' => 255]);
        $table->addColumn('sp_name', 'string', ['notnull' => true, 'length' => 255]);
        $table->addColumn('acs_url', 'string', ['notnull' => true, 'length' => 1024]);
        $table->addColumn('sp_certificate', 'text', ['notnull' => true]);
        $table->addColumn('name_id_format', 'string', ['notnull' => true, 'length' => 255, 'default' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress']);
        $table->addColumn('attribute_mapping', 'text', ['notnull' => true]);
        $table->addColumn('require_signed_requests', 'boolean', ['notnull' => true, 'default' => false]);
        $table->addColumn('is_enabled', 'boolean', ['notnull' => true, 'default' => true]);
        $table->setPrimaryKey(['id']);
        // 255 is portable for utf8mb4 unique indexes on supported database engines.
        $table->addUniqueIndex(['sp_entity_id'], 'saml_provider_sp_entity_id');
        $table->addIndex(['is_enabled'], 'saml_provider_sp_enabled');
        return $schema;
    }
}
