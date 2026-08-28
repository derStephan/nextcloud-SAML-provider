<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Service;

use OCA\SAMLProvider\Db\ServiceProvider;

/**
 * Verifies signatures on incoming AuthnRequests.
 */
class SignatureService {
    private const SIGALG_MAP = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => OPENSSL_ALGO_SHA384,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
    ];

    public function spCanSign(ServiceProvider $sp): bool {
        return trim($sp->getSpCertificate()) !== '';
    }

    /**
     * Redirect binding: verify the query-string signature.
     * Securely reconstructs the signed content from the raw server QUERY_STRING
     * to prevent encoding/decoding inconsistencies from bypassing validation.
     */
    public function verifyRedirectSignature(array $params, ServiceProvider $sp, string $rawQuery): bool {
        $signatureB64 = $params['Signature'] ?? null;
        $sigAlg       = $params['SigAlg'] ?? null;
        $samlRequest  = $params['SAMLRequest'] ?? null;
        if (!is_string($signatureB64) || !is_string($sigAlg) || !is_string($samlRequest)) {
            return false;
        }
        if (!isset(self::SIGALG_MAP[$sigAlg])) {
            return false;
        }

        // To comply with SAML 2.0 specifications, we must use the raw, urlencoded 
        // parameters directly from the QUERY_STRING rather than re-encoding them.
        if ($rawQuery === '') {
            return false;
        }

        // Extract the raw signed values once. Reject duplicate signed parameters:
        // PHP's query decoding has last-value-wins behaviour, which is unsuitable
        // when the signature and the parsed request could otherwise select different values.
        $pairs = explode('&', $rawQuery);
        $rawParams = [];
        foreach ($pairs as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2 || !in_array($parts[0], ['SAMLRequest', 'RelayState', 'SigAlg', 'Signature'], true)) {
                continue;
            }
            if (array_key_exists($parts[0], $rawParams)) {
                return false;
            }
            $rawParams[$parts[0]] = $parts[1];
        }

        $rawRequest = $rawParams['SAMLRequest'] ?? null;
        $rawSigAlg = $rawParams['SigAlg'] ?? null;
        $rawSignature = $rawParams['Signature'] ?? null;
        if ($rawRequest === null || $rawSigAlg === null || $rawSignature === null) {
            return false;
        }
        // Bind framework-decoded values to the exact raw values that are signed.
        // urldecode deliberately mirrors application/x-www-form-urlencoded decoding,
        // including '+' handling, used by PHP for IRequest query parameters.
        if (urldecode($rawRequest) !== $samlRequest
            || urldecode($rawSigAlg) !== $sigAlg
            || urldecode($rawSignature) !== $signatureB64
            || (isset($rawParams['RelayState']) && urldecode($rawParams['RelayState']) !== ($params['RelayState'] ?? null))
            || (!isset($rawParams['RelayState']) && isset($params['RelayState']))) {
            return false;
        }

        // Build exact signed sequence: SAMLRequest=value&RelayState=value&SigAlg=value
        $signedContent = 'SAMLRequest=' . $rawRequest;
        if (isset($rawParams['RelayState'])) {
            $signedContent .= '&RelayState=' . $rawParams['RelayState'];
        }
        $signedContent .= '&SigAlg=' . $rawSigAlg;

        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($sp->getSpCertificate());
        if ($publicKey === false) {
            return false;
        }
        return openssl_verify($signedContent, $signature, $publicKey, self::SIGALG_MAP[$sigAlg]) === 1;
    }

    /**
     * POST binding: verify an enveloped XML signature inside the AuthnRequest.
     * Hardened against XML Signature Wrapping (XSW) and XXE.
     */
    public function verifyPostSignature(string $xml, ServiceProvider $sp): bool {
        // XML signatures are public input. Keep parser errors out of server logs,
        // prohibit DTDs explicitly, and never resolve network resources.
        if (preg_match('/<!DOCTYPE\b/i', $xml) === 1) {
            return false;
        }
        $doc = new \DOMDocument();
        $previousLibxmlErrors = libxml_use_internal_errors(true);
        try {
            $loadStatus = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlErrors);
        }
        if (!$loadStatus) {
            return false;
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');

        // Stricter check for duplicate IDs (Signature Wrapping Mitigations)
        $root = $doc->documentElement;
        if (!$root instanceof \DOMElement) {
            return false;
        }
        $rootId = $root->getAttribute('ID');
        if (trim($rootId) === '') {
            return false;
        }

        // Do not interpolate an attacker-controlled value into XPath. Count IDs
        // directly so quote characters cannot alter query semantics.
        $matchingIds = 0;
        foreach ($doc->getElementsByTagName('*') as $element) {
            if ($element instanceof \DOMElement && $element->getAttribute('ID') === $rootId) {
                ++$matchingIds;
            }
        }
        if ($matchingIds !== 1) {
            return false;
        }

        $sigNodes = $xpath->query('/samlp:AuthnRequest/ds:Signature');
        if ($sigNodes === false || $sigNodes->length !== 1) {
            return false;
        }
        /** @var \DOMElement $sigNode */
        $sigNode = $sigNodes->item(0);

        $canonicalization = $xpath->evaluate('string(ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm)', $sigNode);
        if ($canonicalization !== 'http://www.w3.org/2001/10/xml-exc-c14n#') {
            return false;
        }
        $transforms = $xpath->query('ds:SignedInfo/ds:Reference/ds:Transforms/ds:Transform', $sigNode);
        if ($transforms === false || $transforms->length !== 2
            || !$transforms->item(0) instanceof \DOMElement
            || !$transforms->item(1) instanceof \DOMElement
            || $transforms->item(0)->getAttribute('Algorithm') !== 'http://www.w3.org/2000/09/xmldsig#enveloped-signature'
            || $transforms->item(1)->getAttribute('Algorithm') !== 'http://www.w3.org/2001/10/xml-exc-c14n#') {
            return false;
        }
        $sigAlg = $xpath->evaluate('string(ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $sigNode);
        if (!isset(self::SIGALG_MAP[$sigAlg])) {
            return false;
        }

        $signatureValue = base64_decode(
            (string)preg_replace('/\s+/', '', $xpath->evaluate('string(ds:SignatureValue)', $sigNode)),
            true
        );
        $signedInfoNodes = $xpath->query('ds:SignedInfo', $sigNode);
        if ($signedInfoNodes === false || $signedInfoNodes->length !== 1 || $signatureValue === false) {
            return false;
        }
        /** @var \DOMElement $signedInfoNode */
        $signedInfoNode = $signedInfoNodes->item(0);
        $signedInfoC14n = $signedInfoNode->C14N(true, false);

        // 1) Verify signature
        $publicKey = openssl_pkey_get_public($sp->getSpCertificate());
        if ($publicKey === false) {
            return false;
        }
        if (openssl_verify($signedInfoC14n, $signatureValue, $publicKey, self::SIGALG_MAP[$sigAlg]) !== 1) {
            return false;
        }

        // 2) Verify digest
        $refUri = $xpath->evaluate('string(ds:SignedInfo/ds:Reference/@URI)', $sigNode);
        if ($refUri !== '#' . $rootId) {
            return false;
        }
        $digestAlg = $xpath->evaluate('string(ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm)', $sigNode);
        $expectedDigest = (string)preg_replace('/\s+/', '', $xpath->evaluate(
            'string(ds:SignedInfo/ds:Reference/ds:DigestValue)', $sigNode
        ));

        // Clone into its own document before applying the enveloped-signature
        // transform. A cloneNode() keeps the original owner document; querying that
        // document can return the original Signature node, which must never be
        // removed from the cloned element.
        $cloneDocument = new \DOMDocument();
        $cloneDocument->preserveWhiteSpace = true;
        $clonedRoot = $cloneDocument->importNode($root, true);
        $cloneDocument->appendChild($clonedRoot);
        $clonedXpath = new \DOMXPath($cloneDocument);
        $clonedXpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $clonedXpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $clonedSigNodes = $clonedXpath->query('/samlp:AuthnRequest/ds:Signature');
        if ($clonedSigNodes !== false && $clonedSigNodes->length === 1) {
            $signatureToRemove = $clonedSigNodes->item(0);
            if ($signatureToRemove !== null && $signatureToRemove->parentNode === $clonedRoot) {
                $clonedRoot->removeChild($signatureToRemove);
            }
        }

        $actualDigest = base64_encode(match ($digestAlg) {
            'http://www.w3.org/2001/04/xmlenc#sha256' => hash('sha256', $clonedRoot->C14N(true, false), true),
            'http://www.w3.org/2001/04/xmlenc#sha512' => hash('sha512', $clonedRoot->C14N(true, false), true),
            default => '',
        });

        return $actualDigest !== '' && hash_equals($expectedDigest, $actualDigest);
    }
}
