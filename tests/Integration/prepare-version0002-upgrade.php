<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';
/** Recreate the pre-0.8.5 index state without changing any persisted SP data. */
use OCP\IDBConnection;
use OCP\Server;

$db = Server::get(IDBConnection::class);
$table = $db->getPrefix() . 'saml_provider_sp';
$schemaManager = $db->getSchemaManager();
$indexes = $schemaManager->listTableIndexes($table);
if (!array_key_exists('saml_provider_sp_enabled', $indexes)) {
    throw new RuntimeException('Expected fresh schema index is absent before upgrade-state preparation.');
}
$schemaManager->dropIndex('saml_provider_sp_enabled', $table);
$after = $schemaManager->listTableIndexes($table);
if (array_key_exists('saml_provider_sp_enabled', $after)) {
    throw new RuntimeException('Could not recreate pre-Version0002 index state.');
}
echo "Prepared pre-Version0002 index state.\n";
