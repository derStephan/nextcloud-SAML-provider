<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap-app.php';
set_exception_handler(static function (\Throwable $error): never {
    fwrite(STDERR, 'Integration contract failed: ' . $error->getMessage() . "\n");
    exit(1);
});
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCP\IDBConnection;
use OCP\Server;

$mapper = new ServiceProviderMapper(Server::get(IDBConnection::class));
$entityId = 'urn:test:saml-provider:signature-policy';
try { $mapper->delete($mapper->findByEntityId($entityId)); } catch (\Throwable) {}
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false || !openssl_pkey_export($key, $privateKey)) throw new RuntimeException('Could not create signature-policy private key');
$csr = openssl_csr_new(['commonName' => 'signature-policy-contract'], $key);
$certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1);
if ($certificate === false || !openssl_x509_export($certificate, $certificatePem)) throw new RuntimeException('Could not create signature-policy certificate');
$sp = new ServiceProvider();
$sp->setSpEntityId($entityId); $sp->setSpName('Signature policy contract'); $sp->setAcsUrl('https://sp.example.test/acs');
$sp->setSpCertificate($certificatePem); $sp->setAttributeMapping('{}'); $sp->setRequireSignedRequests(true); $sp->setIsEnabled(true); $mapper->insert($sp);
$id = '_signaturepolicy' . bin2hex(random_bytes(8));
$xml = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="' . $id . '" Version="2.0" IssueInstant="' . gmdate('Y-m-d\\TH:i:s\\Z') . '" AssertionConsumerServiceURL="https://sp.example.test/acs"><saml:Issuer>' . $entityId . '</saml:Issuer></samlp:AuthnRequest>';

// HTTP-Redirect binding signs the exact URL-encoded parameter sequence.
$redirectRequest = rawurlencode(base64_encode(gzdeflate($xml)));
$sigAlg = rawurlencode('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
$redirectSigned = 'SAMLRequest=' . $redirectRequest . '&SigAlg=' . $sigAlg;
if (!openssl_sign($redirectSigned, $redirectSignature, $privateKey, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign Redirect AuthnRequest');

// HTTP-POST binding signs an enveloped AuthnRequest with exclusive canonicalization.
$doc = new DOMDocument();
if (!$doc->loadXML($xml, LIBXML_NONET) || !$doc->documentElement instanceof DOMElement) throw new RuntimeException('Could not parse generated POST AuthnRequest');
$clone = new DOMDocument(); $clone->appendChild($clone->importNode($doc->documentElement, true));
$digest = base64_encode(hash('sha256', (string)$clone->documentElement->C14N(true, false), true));
$signedInfo = '<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/><ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/><ds:Reference URI="#' . $id . '"><ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>' . $digest . '</ds:DigestValue></ds:Reference></ds:SignedInfo>';
$info = new DOMDocument();
if (!$info->loadXML($signedInfo, LIBXML_NONET) || !$info->documentElement instanceof DOMElement || !openssl_sign((string)$info->documentElement->C14N(true, false), $postSignature, $privateKey, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign POST AuthnRequest');
$postXml = str_replace('</samlp:AuthnRequest>', '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' . $signedInfo . '<ds:SignatureValue>' . base64_encode($postSignature) . '</ds:SignatureValue></ds:Signature></samlp:AuthnRequest>', $xml);
echo json_encode([
    'unsignedPost' => base64_encode($xml), 'signedPost' => base64_encode($postXml),
    'unsignedRedirect' => 'SAMLRequest=' . $redirectRequest,
    'signedRedirect' => $redirectSigned . '&Signature=' . rawurlencode(base64_encode($redirectSignature)),
], JSON_THROW_ON_ERROR), "\n";
