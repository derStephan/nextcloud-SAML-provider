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
        $slo      = htmlspecialchars($this->idpConfig->getSloUrl(), ENT_XML1);
        $cert     = $this->idpConfig->getCertificateBase64();
        $org      = htmlspecialchars($this->idpConfig->getOrgName(), ENT_XML1);
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
    <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="{$slo}"/>
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
        if ($raw === false) {
            throw new \InvalidArgumentException('SAMLRequest is not valid base64');
        }
        if ($binding === 'redirect') {
            $inflated = @gzinflate($raw);
            if ($inflated === false) {
                throw new \InvalidArgumentException('SAMLRequest DEFLATE decompression failed');
            }
            $raw = $inflated;
        }

        $doc = new \DOMDocument();
        // SECURITY: Prevent XXE Completely
        $oldEntityLoader = libxml_disable_entity_loader(true);
        $loadStatus = $doc->loadXML($raw, LIBXML_NONET | LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_DTDATTR);
        libxml_disable_entity_loader($oldEntityLoader);

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

        // Robust XPath querying using local-name() to bypass namespace prefix issues (e.g. default namespace)
        $issuer = trim($xpath->evaluate('string(//*[local-name()="Issuer"])'));
        $policy = $xpath->evaluate('string(//*[local-name()="NameIDPolicy"]/@Format)');

        return [
            'id'           => $root->getAttribute('ID'),
            'issuer'       => $issuer,
            'acsUrl'       => $root->getAttribute('AssertionConsumerServiceURL') ?: null,
            'nameIdPolicy' => $policy !== '' ? $policy : null,
            'rawXml'       => $raw,
        ];
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
            ? $this->signatureService->verifyRedirectSignature($requestParams, $sp)
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

        if ($sp->getSignAssertions()) {
            $response = $this->signXml($response, $responseId);
        }

        return base64_encode($response);
    }

    private function resolveNameId(ServiceProvider $sp, IUser $user): string {
        return match ($sp->getNameIdFormat()) {
            'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'
                => hash('sha256', $user->getUID() . '|' . $sp->getSpEntityId()),
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

        return preg_replace(
            '/(<saml2:Issuer>.*?<\/saml2:Issuer>)/s',
            '$1' . $signature,
            $xml,
            1
        ) ?? $xml;
    }

    private function e(string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
