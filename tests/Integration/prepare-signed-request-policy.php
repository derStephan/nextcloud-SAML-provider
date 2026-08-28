<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCP\IDBConnection;
use OCP\Server;
set_exception_handler(static function (\Throwable $error): never { fwrite(STDERR, 'Integration contract failed: ' . $error->getMessage() . "\n"); exit(1); });
$mapper = new ServiceProviderMapper(Server::get(IDBConnection::class));
$entityId = 'urn:test:saml-provider:signature-policy';
try { $mapper->delete($mapper->findByEntityId($entityId)); } catch (\Throwable) {}
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($key === false || !openssl_pkey_export($key, $privateKey)) { throw new RuntimeException('Could not create test SP key'); }
$csr = openssl_csr_new(['commonName' => 'saml-policy-contract.example.test'], $key, ['digest_alg' => 'sha256']);
$certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
if ($certificate === false || !openssl_x509_export($certificate, $certificatePem)) { throw new RuntimeException('Could not create test SP certificate'); }
$sp = new ServiceProvider();
$sp->setSpEntityId($entityId); $sp->setSpName('Signature policy contract');
$sp->setAcsUrl('https://sp.example.test/acs'); $sp->setSpCertificate($certificatePem);
$sp->setAttributeMapping('{}'); $sp->setRequireSignedRequests(true); $sp->setIsEnabled(true);
$mapper->insert($sp);
echo json_encode(['entityId' => $entityId, 'privateKey' => base64_encode($privateKey)], JSON_THROW_ON_ERROR), "\n";
