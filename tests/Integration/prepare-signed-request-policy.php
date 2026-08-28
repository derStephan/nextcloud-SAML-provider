<?php
declare(strict_types=1);
/** Prepare a real enabled SP whose unsigned AuthnRequests must be rejected by the public SSO route. */
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCP\IDBConnection;
use OCP\Server;
$mapper = new ServiceProviderMapper(Server::get(IDBConnection::class));
$entityId = 'urn:test:saml-provider:unsigned-required';
try {
    $mapper->delete($mapper->findByEntityId($entityId));
} catch (\Throwable) {
}
$sp = new ServiceProvider();
$sp->setSpEntityId($entityId);
$sp->setSpName('Unsigned request policy contract');
$sp->setAcsUrl('https://sp.example.test/acs');
$sp->setSpCertificate('configured-for-negative-signature-contract');
$sp->setAttributeMapping('{}');
$sp->setRequireSignedRequests(true);
$sp->setIsEnabled(true);
$mapper->insert($sp);
echo $entityId, "\n";
