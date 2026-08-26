<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Service;

use OCA\SAMLProvider\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;

class IdpConfigService {
    private const KEY_CERT = 'idp_certificate';
    private const KEY_KEY  = 'idp_private_key';
    private const KEY_ORG  = 'idp_org_name';

    public function __construct(
        private IAppConfig $appConfig,
        private IURLGenerator $urlGenerator,
    ) {}

    public function getEntityId(): string {
        // Convention: metadata URL doubles as entityID (like many SimpleSAMLphp setups)
        return $this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID . '/saml/metadata');
    }

    public function getSsoUrl(): string {
        return $this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID . '/saml/sso');
    }

    public function getSloUrl(): string {
        return $this->urlGenerator->getAbsoluteURL('/apps/' . Application::APP_ID . '/saml/slo');
    }

    public function getOrgName(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_ORG, 'Nextcloud');
    }

    public function setOrgName(string $name): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_ORG, $name);
    }

    public function getCertificate(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_CERT, '');
    }

    public function getPrivateKey(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_KEY, '', lazy: true);
    }

    public function hasCertificate(): bool {
        return $this->getCertificate() !== '' && $this->getPrivateKey() !== '';
    }

    /** Generates a self-signed 4096-bit RSA cert valid 10 years. */
    public function generateCertificate(string $commonName): void {
        $dn = ['commonName' => $commonName, 'organizationName' => $this->getOrgName()];
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed');
        }
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certOut);
        openssl_pkey_export($key, $keyOut);
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_CERT, $certOut);
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_KEY, $keyOut, lazy: true, sensitive: true);
    }

    public function setCertificate(string $certPem, string $keyPem): void {
        if (openssl_x509_read($certPem) === false) {
            throw new \InvalidArgumentException('Invalid X.509 certificate');
        }
        if (openssl_pkey_get_private($keyPem) === false) {
            throw new \InvalidArgumentException('Invalid private key');
        }
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_CERT, $certPem);
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_KEY, $keyPem, lazy: true, sensitive: true);
    }

    /** Base64-encoded DER of the cert, no PEM armor – for embedding in SAML XML. */
    public function getCertificateBase64(): string {
        return self::pemToBase64($this->getCertificate());
    }

    public static function pemToBase64(string $pem): string {
        return (string)preg_replace(
            '/-----BEGIN [^-]+-----|-----END [^-]+-----|\s/',
            '', $pem
        );
    }
}
