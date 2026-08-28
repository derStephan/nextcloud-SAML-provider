<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
/** Adds the enabled-service lookup index to upgraded installations. */
class Version0002Date20260828000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */ $schema = $schemaClosure();
        if (!$schema->hasTable('saml_provider_sp')) return null;
        $table = $schema->getTable('saml_provider_sp');
        if ($table->hasIndex('saml_provider_sp_enabled')) return null;
        $table->addIndex(['is_enabled'], 'saml_provider_sp_enabled');
        return $schema;
    }
}
