<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Service;

use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Minimal SAML 2.0 IdP implementation (Web Browser SSO profile).
 */
class SamlService {
    /** Maximum decoded AuthnRequest size (1 MiB), including post-inflate Redirect requests. */
    private const MAX_AUTHN_REQUEST_BYTES = 1048576;

    private const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';
    private const NS_SAML  = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(
        private IdpConfigService $idpConfig,
        private ServiceProviderMapper $spMapper,
        private SignatureService $signatureService,
        private LoggerInterface $logger,
    ) {}

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    public function buildMetadataXml(): string {
        $entityId = htmlspecialchars($this->idpConfig->getEntityId(), ENT_XML1);
        $sso      = htmlspecialchars($this->idpConfig->getSsoUrl(), ENT_XML1);
                $cert     = $this->idpConfig->getCertificateBase64();
        $org      = 'Nextcloud';
        $wantSigned = $this->spMapper->anyRequiresSignedRequests() ? 'true' : 'false';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
  <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"
                       WantAuthnRequestsSigned="{$wantSigned}">
    <md:KeyDescriptor use="signing">
      <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
        <ds:X509Data><ds:X509Certificate>{$cert}</ds:X509Certificate></ds:X509Data>
      </ds:KeyInfo>
    </md:KeyDescriptor>
    <md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>
    <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$sso}"/>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="{$sso}"/>
  </md:IDPSSODescriptor>
  <md:Organization>
    <md:OrganizationName xml:lang="en">{$org}</md:OrganizationName>
    <md:OrganizationDisplayName xml:lang="en">{$org}</md:OrganizationDisplayName>
    <md:OrganizationURL xml:lang="en">{$sso}</md:OrganizationURL>
  </md:Organization>
</md:EntityDescriptor>
XML;
    }

    // ------------------------------------------------------------------
    // AuthnRequest handling
    // ------------------------------------------------------------------

    /**
     * Decodes an AuthnRequest received via HTTP-Redirect (DEFLATE+base64)
     * or HTTP-POST (plain base64) binding.
     * @return array{id:string, issuer:string, acsUrl:?string, nameIdPolicy:?string, rawXml:string}
     */
    public function parseAuthnRequest(string $samlRequest, string $binding): array {
        $raw = base64_decode($samlRequest, true);
        if ($raw === false || strlen($raw) > self::MAX_AUTHN_REQUEST_BYTES) {
            throw new \InvalidArgumentException('SAMLRequest is invalid or exceeds the size limit');
        }
        if ($binding === 'redirect') {
            $inflated = @gzinflate($raw, self::MAX_AUTHN_REQUEST_BYTES + 1);
            if ($inflated === false || strlen($inflated) > self::MAX_AUTHN_REQUEST_BYTES) {
                throw new \InvalidArgumentException('SAMLRequest DEFLATE decompression failed or exceeds the size limit');
            }
            $raw = $inflated;
        }

        // SAML AuthnRequests never need a DTD. Reject it before parsing so internal
        // entity declarations cannot be used for expansion or parser resource attacks.
        if (preg_match('/<!DOCTYPE\b/i', $raw) === 1) {
            throw new \InvalidArgumentException('SAMLRequest must not contain a DTD');
        }
        $doc = new \DOMDocument();
        // Do not load DTDs or substitute entities; LIBXML_NONET prevents network access.
        $previousLibxmlErrors = libxml_use_internal_errors(true);
        try {
            $loadStatus = $doc->loadXML($raw, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlErrors);
        }

        if (!$loadStatus) {
            throw new \InvalidArgumentException('SAMLRequest is not well-formed XML');
        }

        $root = $doc->documentElement;
        if (!$root instanceof \DOMElement
            || $root->localName !== 'AuthnRequest'
            || $root->namespaceURI !== self::NS_SAMLP) {
            throw new \InvalidArgumentException('Not a SAML AuthnRequest');
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('samlp', self::NS_SAMLP);
        $xpath->registerNamespace('saml', self::NS_SAML);

        // Only direct protocol children are authoritative. A value in Extensions or
        // another nested XML subtree must never select the Service Provider.
        $issuer = trim((string)$xpath->evaluate('string(/samlp:AuthnRequest/saml:Issuer)'));
        $requestId = $root->getAttribute('ID');
        if ($issuer === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9._-]{0,255}$/D', $requestId)
            || $root->getAttribute('Version') !== '2.0') {
            throw new \InvalidArgumentException('SAMLRequest is missing required protocol fields');
        }
        $destination = $root->getAttribute('Destination');
        if ($destination !== '' && !hash_equals($this->idpConfig->getSsoUrl(), $destination)) {
            throw new \InvalidArgumentException('SAMLRequest Destination does not match this IdP');
        }
        $issueInstant = $root->getAttribute('IssueInstant');
        if ($issueInstant === '') {
            throw new \InvalidArgumentException('SAMLRequest is missing IssueInstant');
        }
        try {
            $issuedAt = new \DateTimeImmutable($issueInstant);
        } catch (\Exception) {
            throw new \InvalidArgumentException('SAMLRequest has an invalid IssueInstant');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (abs($now->getTimestamp() - $issuedAt->getTimestamp()) > 300) {
            throw new \InvalidArgumentException('SAMLRequest IssueInstant is outside the permitted clock skew');
        }
        $protocolBinding = $root->getAttribute('ProtocolBinding');
        if ($protocolBinding !== '' && $protocolBinding !== 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST') {
            throw new \InvalidArgumentException('SAMLRequest requests an unsupported response binding');
        }
        $nameIdPolicy = $xpath->evaluate('string(/samlp:AuthnRequest/samlp:NameIDPolicy/@Format)');
        if ($nameIdPolicy !== '' && !in_array($nameIdPolicy, [
            'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
            'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified',
        ], true)) {
            throw new \InvalidArgumentException('SAMLRequest requests an unsupported NameID format');
        }

        return [
            'id'           => $requestId,
            'issuer'       => $issuer,
            'acsUrl'       => $root->getAttribute('AssertionConsumerServiceURL') ?: null,
            'nameIdPolicy' => $nameIdPolicy !== '' ? $nameIdPolicy : null,
            'rawXml'       => $raw,
        ];
    }

    /** Reject a request that asks this SP for a different NameID representation. */
    public function enforceNameIdPolicy(array $authnRequest, ServiceProvider $sp): void {
        $requestedFormat = $authnRequest['nameIdPolicy'] ?? null;
        if ($requestedFormat === null || in_array($requestedFormat, [
            'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified',
        ], true)) {
            return;
        }
        if (!is_string($requestedFormat) || !hash_equals($sp->getNameIdFormat(), $requestedFormat)) {
            throw new \RuntimeException('SAMLRequest NameIDPolicy does not match the configured service format');
        }
    }

    public function resolveServiceProvider(string $entityId): ServiceProvider {
        $sp = $this->spMapper->findByEntityId($entityId);
        if (!$sp->getIsEnabled()) {
            throw new \RuntimeException('Service Provider is disabled: ' . $entityId);
        }
        return $sp;
    }

    /** @throws \RuntimeException when disabled or not found */
    public function resolveServiceProviderById(int $id): ServiceProvider {
        $sp = $this->spMapper->find($id);
        if (!$sp->getIsEnabled()) {
            throw new \RuntimeException('Service Provider is disabled');
        }
        return $sp;
    }

    /**
     * Enforces the per-SP AuthnRequest signature policy.
     */
    public function enforceRequestSignature(
        array $authnRequest,
        string $binding,
        array $requestParams,
        ServiceProvider $sp,
        string $rawQuery = '',
    ): void {
        $canVerify = $this->signatureService->spCanSign($sp);

        if (!$sp->getRequireSignedRequests()) {
            return;
        }
        if (!$canVerify) {
            throw new \RuntimeException(
                'SP requires signed AuthnRequests but no SP certificate is configured'
            );
        }

        $valid = $binding === 'redirect'
            ? $this->signatureService->verifyRedirectSignature($requestParams, $sp, $rawQuery)
            : $this->signatureService->verifyPostSignature($authnRequest['rawXml'], $sp);

        if (!$valid) {
            $this->logger->warning('Rejected AuthnRequest with missing/invalid signature', [
                'app' => 'saml_provider', 'sp' => $sp->getSpEntityId(), 'binding' => $binding,
            ]);
            throw new \RuntimeException('Invalid or missing AuthnRequest signature');
        }
    }

    // ------------------------------------------------------------------
    // Response / Assertion generation
    // ------------------------------------------------------------------

    public function buildResponse(
        ServiceProvider $sp,
        IUser $user,
        ?string $inResponseTo,
        ?string $acsUrlOverride,
    ): string {
        // Metadata and response signing must share one certificate-validity policy.
        if (!$this->idpConfig->hasCertificate()) {
            throw new \RuntimeException('IdP signing certificate is unavailable or expired');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $notOnOrAfter = $now->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z');
        $issueInstant = $now->format('Y-m-d\TH:i:s\Z');

        $responseId  = '_' . bin2hex(random_bytes(16));
        $assertionId = '_' . bin2hex(random_bytes(16));

        $issuer      = $this->idpConfig->getEntityId();
        $destination = $acsUrlOverride ?? $sp->getAcsUrl();
        
        // Security Hardening: ACS-URL restriction
        if ($destination !== $sp->getAcsUrl()) {
            $this->logger->warning('AuthnRequest ACS URL mismatch, using registered ACS URL', [
                'app' => 'saml_provider', 'requested' => $destination, 'registered' => $sp->getAcsUrl(),
            ]);
            $destination = $sp->getAcsUrl();
        }

        $nameIdValue = $this->resolveNameId($sp, $user);
        $attributesXml = $this->buildAttributesXml($sp, $user);

        $inResponseToAttr = $inResponseTo !== null
            ? ' InResponseTo="' . htmlspecialchars($inResponseTo, ENT_XML1) . '"'
            : '';

        $assertion = <<<XML
<saml2:Assertion xmlns:saml2="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$assertionId}" IssueInstant="{$issueInstant}" Version="2.0">
  <saml2:Issuer>{$this->e($issuer)}</saml2:Issuer>
  <saml2:Subject>
    <saml2:NameID Format="{$this->e($sp->getNameIdFormat())}">{$this->e($nameIdValue)}</saml2:NameID>
    <saml2:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
      <saml2:SubjectConfirmationData NotOnOrAfter="{$notOnOrAfter}" Recipient="{$this->e($destination)}"{$inResponseToAttr}/>
    </saml2:SubjectConfirmation>
  </saml2:Subject>
  <saml2:Conditions NotBefore="{$issueInstant}" NotOnOrAfter="{$notOnOrAfter}">
    <saml2:AudienceRestriction><saml2:Audience>{$this->e($sp->getSpEntityId())}</saml2:Audience></saml2:AudienceRestriction>
  </saml2:Conditions>
  <saml2:AuthnStatement AuthnInstant="{$issueInstant}" SessionIndex="{$assertionId}">
    <saml2:AuthnContext><saml2:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml2:AuthnContextClassRef></saml2:AuthnContext>
  </saml2:AuthnStatement>
  {$attributesXml}
</saml2:Assertion>
XML;

        $signedAssertion = $this->signXml($assertion, $assertionId);

        $response = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<saml2p:Response xmlns:saml2p="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml2="urn:oasis:names:tc:SAML:2.0:assertion"
                 Destination="{$this->e($destination)}" ID="{$responseId}" IssueInstant="{$issueInstant}" Version="2.0"{$inResponseToAttr}>
  <saml2:Issuer>{$this->e($issuer)}</saml2:Issuer>
  <saml2p:Status><saml2p:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></saml2p:Status>
  {$signedAssertion}
</saml2p:Response>
XML;

        // Sign both the assertion and its enclosing response. Older releases
        // exposed a misleading per-SP toggle; security must not depend on it.
        $response = $this->signXml($response, $responseId);

        return base64_encode($response);
    }

    private function resolveNameId(ServiceProvider $sp, IUser $user): string {
        return match ($sp->getNameIdFormat()) {
            'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'
                => hash_hmac('sha256', $user->getUID() . '|' . $sp->getSpEntityId(), $this->idpConfig->getPersistentNameIdPepper()),
            'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'
                => $user->getEMailAddress() ?: $user->getUID(),
            default => $user->getUID(),
        };
    }

    private function buildAttributesXml(ServiceProvider $sp, IUser $user): string {
        $defaults = [
            'uid'         => $user->getUID(),
            'displayName' => $user->getDisplayName(),
            'mail'        => $user->getEMailAddress() ?? '',
        ];
        $mapping = json_decode($sp->getAttributeMapping() ?: '{}', true) ?: [];

        $attrs = $defaults;
        foreach ($mapping as $samlName => $source) {
            $attrs[$samlName] = $defaults[$source] ?? '';
        }

        $xml = '<saml2:AttributeStatement>';
        $hasAny = false;
        foreach ($attrs as $name => $value) {
            if ($value === '') {
                continue;
            }
            $hasAny = true;
            $xml .= '<saml2:Attribute Name="' . $this->e($name)
                  . '" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic">'
                  . '<saml2:AttributeValue>' . $this->e($value) . '</saml2:AttributeValue></saml2:Attribute>';
        }
        $xml .= '</saml2:AttributeStatement>';
        return $hasAny ? $xml : '';
    }

    private function signXml(string $xml, string $referenceId): string {
        $doc = new \DOMDocument();
        // Preserve whitespace exactly as emitted. XML canonicalization retains text
        // nodes, including indentation/newlines; stripping them while creating the
        // DigestValue makes a strict verifier calculate a different reference digest.
        $doc->preserveWhiteSpace = true;
        if (!$doc->loadXML($xml, LIBXML_NONET) || !$doc->documentElement instanceof \DOMElement) {
            throw new \RuntimeException('Failed to build SAML XML for signing');
        }

        $canonical = $doc->documentElement->C14N(true, false);
        if ($canonical === false) {
            throw new \RuntimeException('Failed to canonicalize SAML XML for signing');
        }
        $digest = base64_encode(hash('sha256', $canonical, true));

        $signedInfo = '<ds:SignedInfo xmlns:ds="' . self::NS_DS . '">'
            . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            . '<ds:Reference URI="#' . $referenceId . '">'
            . '<ds:Transforms>'
            . '<ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
            . '<ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
            . '</ds:Transforms>'
            . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            . '<ds:DigestValue>' . $digest . '</ds:DigestValue>'
            . '</ds:Reference></ds:SignedInfo>';

        // XMLDSig signs the canonicalized SignedInfo node, not its source-string
        // representation. The declared algorithm is exclusive XML canonicalization.
        // Signing the raw string breaks verification in strict XMLDSig implementations
        // such as the OneLogin/php-saml library used by Kimai.
        $signedInfoDocument = new \DOMDocument();
        $signedInfoDocument->preserveWhiteSpace = false;
        if (!$signedInfoDocument->loadXML($signedInfo, LIBXML_NONET)
            || !$signedInfoDocument->documentElement instanceof \DOMElement) {
            throw new \RuntimeException('Failed to build SAML SignedInfo');
        }
        $canonicalSignedInfo = $signedInfoDocument->documentElement->C14N(true, false);
        if ($canonicalSignedInfo === false) {
            throw new \RuntimeException('Failed to canonicalize SAML SignedInfo');
        }

        $privateKey = openssl_pkey_get_private($this->idpConfig->getPrivateKey());
        $signatureValue = '';
        if ($privateKey === false
            || !openssl_sign($canonicalSignedInfo, $signatureValue, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign SAML element');
        }

        $signature = '<ds:Signature xmlns:ds="' . self::NS_DS . '">'
            . $signedInfo
            . '<ds:SignatureValue>' . base64_encode($signatureValue) . '</ds:SignatureValue>'
            . '<ds:KeyInfo><ds:X509Data><ds:X509Certificate>'
            . $this->idpConfig->getCertificateBase64()
            . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo></ds:Signature>';

        // Insert the generated Signature structurally, immediately after this element's
        // Issuer. Do not modify signed XML using string/regex operations.
        $signatureDocument = new \DOMDocument();
        if (!$signatureDocument->loadXML($signature, LIBXML_NONET) || !$signatureDocument->documentElement instanceof \DOMElement) {
            throw new \RuntimeException('Failed to build XML signature');
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('saml2', self::NS_SAML);
        $issuerNodes = $xpath->query('./saml2:Issuer', $doc->documentElement);
        if ($issuerNodes === false || $issuerNodes->length !== 1) {
            throw new \RuntimeException('SAML element has no unique Issuer for signature insertion');
        }
        $issuer = $issuerNodes->item(0);
        $signatureNode = $doc->importNode($signatureDocument->documentElement, true);
        $parent = $doc->documentElement;
        $parent->insertBefore($signatureNode, $issuer->nextSibling);
        $result = $doc->saveXML($doc->documentElement);
        if ($result === false) {
            throw new \RuntimeException('Failed to serialize signed SAML XML');
        }
        return $result;
    }

    private function e(string $value): string {
        // XML 1.0 forbids several C0 control characters. Strip them before
        // escaping user profile data so one malformed display name cannot turn
        // a login attempt into a server error during signature construction.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
