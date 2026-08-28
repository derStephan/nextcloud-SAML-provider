<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';
set_exception_handler(static function (\Throwable $error): never {
    fwrite(STDERR, 'Integration contract failed: ' . $error->getMessage() . "\n");
    exit(1);
});
/**
 * Public-OCP-only verification of the real Version0002 result.
 * No public read-only OCP schema inspector exists. The narrow portable DDL probe
 * proves the named index exists, then restores it in finally so every test pass
 * ends with the migration's valid schema state.
 */
use OCP\IDBConnection;
use OCP\Server;
$db = Server::get(IDBConnection::class);
$cursor = $db->getQueryBuilder()->select('id', 'sp_entity_id', 'sp_certificate', 'attribute_mapping', 'is_enabled')
    ->from('saml_provider_sp')->setMaxResults(1)->executeQuery();
try {
    $row = $cursor->fetchAssociative();
} finally {
    $cursor->closeCursor();
}
if ($row === false) {
    throw new RuntimeException('Upgrade contract lost the persistence data created before migration.');
}
$index = 'saml_provider_sp_enabled';
$table = $db->getPrefix() . 'saml_provider_sp';
$driver = getenv('NEXTCLOUD_DATABASE') ?: 'sqlite';
$drop = $driver === 'mysql' ? "DROP INDEX $index ON $table" : "DROP INDEX $index";
$create = "CREATE INDEX $index ON $table (is_enabled)";
$dropped = false;
try {
    $db->executeStatement($drop);
    $dropped = true;
} finally {
    if ($dropped) {
        $db->executeStatement($create);
    }
}
echo "Version0002 additive upgrade index contract passed and restored the verified schema.\n";
