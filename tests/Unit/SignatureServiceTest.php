<?php
declare(strict_types=1);
namespace OCA\SAMLProvider\Tests\Unit;
use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Service\SignatureService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
#[CoversClass(SignatureService::class)]
#[UsesClass(ServiceProvider::class)]
final class SignatureServiceTest extends TestCase {
    public function testVerifiesValidRedirectSignatureAndRejectsTampering(): void {
        [$certificate, $privateKey] = $this->newCertificate(); $sp = $this->provider($certificate);
        $request = rawurlencode(base64_encode('request')); $relay = rawurlencode('https://sp.example.test/after'); $algorithm = rawurlencode('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'); $signed = "SAMLRequest={$request}&RelayState={$relay}&SigAlg={$algorithm}";
        openssl_sign($signed, $signature, $privateKey, OPENSSL_ALGO_SHA256); $_SERVER['QUERY_STRING'] = $signed . '&Signature=' . rawurlencode(base64_encode($signature));
        $params = ['SAMLRequest' => base64_decode(rawurldecode($request)), 'RelayState' => rawurldecode($relay), 'SigAlg' => rawurldecode($algorithm), 'Signature' => base64_encode($signature)]; $service = new SignatureService();
        self::assertTrue($service->spCanSign($sp)); self::assertTrue($service->verifyRedirectSignature($params, $sp)); $params['Signature'] = base64_encode('invalid'); self::assertFalse($service->verifyRedirectSignature($params, $sp));
    }
    public function testVerifiesValidSignedPostRequestAndRejectsDigestTampering(): void {
        [$certificate, $privateKey] = $this->newCertificate(); $sp = $this->provider($certificate); $service = new SignatureService();
        $xml = $this->signedAuthnRequest('_post-request', $privateKey);
        self::assertTrue($service->verifyPostSignature($xml, $sp));
        self::assertFalse($service->verifyPostSignature(str_replace('https://sp.example.test/acs', 'https://evil.example.test/acs', $xml), $sp));
    }
    public function testRejectsMissingUnsupportedAndMalformedSignatureInputs(): void { $sp = new ServiceProvider(); $service = new SignatureService(); self::assertFalse($service->spCanSign($sp)); self::assertFalse($service->verifyRedirectSignature([], $sp)); self::assertFalse($service->verifyPostSignature('<xml/>', $sp)); self::assertFalse($service->verifyPostSignature('<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="_x"/>', $sp)); }
    private function provider(string $certificate): ServiceProvider { $sp = new ServiceProvider(); $sp->setSpCertificate($certificate); return $sp; }
    private function signedAuthnRequest(string $id, string $privateKey): string {
        $unsigned = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="'.$id.'" AssertionConsumerServiceURL="https://sp.example.test/acs"><saml:Issuer>https://sp.example.test/metadata</saml:Issuer></samlp:AuthnRequest>';
        $doc = new \DOMDocument(); $doc->preserveWhiteSpace = true; $doc->loadXML($unsigned); $digest = base64_encode(hash('sha256', $doc->documentElement->C14N(true, false), true));
        $signedInfo = '<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/><ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/><ds:Reference URI="#'.$id.'"><ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/><ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/></ds:Transforms><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>'.$digest.'</ds:DigestValue></ds:Reference></ds:SignedInfo>';
        $infoDoc = new \DOMDocument(); $infoDoc->loadXML($signedInfo); openssl_sign($infoDoc->documentElement->C14N(true, false), $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureXml = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.$signedInfo.'<ds:SignatureValue>'.base64_encode($signature).'</ds:SignatureValue></ds:Signature>';
        return str_replace('</saml:Issuer>', '</saml:Issuer>'.$signatureXml, $unsigned);
    }
    /** @return array{string,string} */ private function newCertificate(): array { $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); $csr = openssl_csr_new(['commonName' => 'test'], $key); $cert = openssl_csr_sign($csr, null, $key, 1); openssl_x509_export($cert, $certPem); openssl_pkey_export($key, $keyPem); return [$certPem, $keyPem]; }
}
