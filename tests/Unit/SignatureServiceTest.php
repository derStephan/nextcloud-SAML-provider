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
        [$certificate, $privateKey] = $this->newCertificate();
        $sp = new ServiceProvider(); $sp->setSpCertificate($certificate);
        $request = rawurlencode(base64_encode('request')); $relay = rawurlencode('https://sp.example.test/after'); $algorithm = rawurlencode('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signed = "SAMLRequest={$request}&RelayState={$relay}&SigAlg={$algorithm}";
        openssl_sign($signed, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $_SERVER['QUERY_STRING'] = $signed . '&Signature=' . rawurlencode(base64_encode($signature));
        $params = ['SAMLRequest' => base64_decode(rawurldecode($request)), 'RelayState' => rawurldecode($relay), 'SigAlg' => rawurldecode($algorithm), 'Signature' => base64_encode($signature)];
        $service = new SignatureService();
        self::assertTrue($service->spCanSign($sp)); self::assertTrue($service->verifyRedirectSignature($params, $sp));
        $params['Signature'] = base64_encode('invalid'); self::assertFalse($service->verifyRedirectSignature($params, $sp));
    }
    public function testRejectsMissingOrUnsupportedSignatureInputs(): void { $sp = new ServiceProvider(); $service = new SignatureService(); self::assertFalse($service->spCanSign($sp)); self::assertFalse($service->verifyRedirectSignature([], $sp)); self::assertFalse($service->verifyPostSignature('<xml/>', $sp)); }
    /** @return array{string,string} */ private function newCertificate(): array { $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); $csr = openssl_csr_new(['commonName' => 'test'], $key); $cert = openssl_csr_sign($csr, null, $key, 1); openssl_x509_export($cert, $certPem); openssl_pkey_export($key, $keyPem); return [$certPem, $keyPem]; }
}
