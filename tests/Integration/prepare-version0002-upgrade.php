<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';
set_exception_handler(static function (\Throwable $error): never {
    fwrite(STDERR, 'Integration contract failed: ' . $error->getMessage() . "\n");
    exit(1);
});
/**
 * Prepare a pre-Version0002 state using only the public OCP IDBConnection API.
 * Schema inspection through private adapter internals is deliberately forbidden.
 */
use OCP\IDBConnection;
use OCP\Server;
$db = Server::get(IDBConnection::class);
$index = 'saml_provider_sp_enabled';
$table = $db->getPrefix() . 'saml_provider_sp';
$driver = getenv('NEXTCLOUD_DATABASE') ?: 'sqlite';
$sql = $driver === 'mysql'
    ? "DROP INDEX $index ON $table"
    : "DROP INDEX $index";
$db->executeStatement($sql);
echo "Prepared pre-Version0002 index state.\n";
