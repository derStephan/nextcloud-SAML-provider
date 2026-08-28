<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';
// Direct integration contracts run in the CLI, not over HTTP. Nextcloud's web
// exception renderer can otherwise turn an uncaught PHP exception into markup while
// leaving the CLI process successful. Make every uncaught contract failure explicit.
set_exception_handler(static function (\Throwable $error): never {
    fwrite(STDERR, 'Integration contract failed: ' . $error->getMessage() . "\n");
    exit(1);
});
/** Real Nextcloud DBAL CRUD: verifies production mapper insert, read, update, filtering, unique constraint, and delete. */
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;
$mapper = new ServiceProviderMapper(Server::get(IDBConnection::class));
$entityId = 'urn:test:saml-provider:' . bin2hex(random_bytes(8));
$sp = new ServiceProvider();
$sp->setSpEntityId($entityId); $sp->setSpName('Persistence contract');
$sp->setAcsUrl('https://sp.example.test/acs'); $sp->setSpCertificate('');
$sp->setAttributeMapping('{}');
$sp->setRequireSignedRequests(false); $sp->setIsEnabled(true);
$inserted = $mapper->insert($sp); $id = $inserted->getId();
if (!is_int($id) || $id < 1) throw new RuntimeException('Insert did not assign an ID.');
$loaded = $mapper->find($id);
if ($loaded->getSpEntityId() !== $entityId || $loaded->getSpCertificate() !== '' || $loaded->getAttributeMapping() !== '{}') throw new RuntimeException('Find did not round-trip values.');
$loaded->setSpName('Updated'); $loaded->setIsEnabled(false); $updated = $mapper->update($loaded);
if ($updated->getSpName() !== 'Updated' || $mapper->findAllEnabled() !== []) throw new RuntimeException('Update or enabled filter failed.');
try { $duplicate = new ServiceProvider(); $duplicate->setSpEntityId($entityId); $duplicate->setSpName('Duplicate'); $duplicate->setAcsUrl('https://sp.example.test/duplicate'); $duplicate->setSpCertificate(''); $duplicate->setAttributeMapping('{}'); $duplicate->setIsEnabled(true); $mapper->insert($duplicate); throw new RuntimeException('Unique constraint not enforced.'); } catch (Throwable $e) { if ($e instanceof RuntimeException && $e->getMessage() === 'Unique constraint not enforced.') throw $e; }
$mapper->delete($updated);
try { $mapper->find($id); throw new RuntimeException('Delete failed.'); } catch (DoesNotExistException) {}
echo "ServiceProviderMapper persistence contract passed.
";
