<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';
/** Verifies persisted data and enabled-service index after Nextcloud executes the real Version0002 migration. */
use OCP\IDBConnection;
use OCP\Server;

$db = Server::get(IDBConnection::class);
$schemaManager = $db->getSchemaManager();
$indexes = $schemaManager->listTableIndexes($db->getPrefix() . 'saml_provider_sp');
if (!array_key_exists('saml_provider_sp_enabled', $indexes)) {
    throw new RuntimeException('Upgrade migration did not create saml_provider_sp_enabled.');
}
$columns = $schemaManager->listTableColumns($db->getPrefix() . 'saml_provider_sp');
$rows = $db->getQueryBuilder()->select('id')->from('saml_provider_sp')->setMaxResults(1)->executeQuery()->fetchAllAssociative();
if ($rows === []) {
    throw new RuntimeException('Upgrade contract lost the persistence data created before migration.');
}
foreach (['sp_entity_id', 'sp_certificate', 'attribute_mapping', 'is_enabled'] as $column) {
    if (!array_key_exists($column, $columns)) {
        throw new RuntimeException("Upgrade schema lost required column: $column");
    }
}
echo "Version0002 additive upgrade index contract passed.\n";
