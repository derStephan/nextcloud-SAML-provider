<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Service;

use OCA\SAMLProvider\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;

class IdpConfigService {
    private const KEY_CERT = 'idp_certificate';
    private const KEY_KEY  = 'idp_private_key';
    private const KEY_NAMEID_PEPPER = 'persistent_nameid_pepper';

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

    public function getCertificate(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_CERT, '');
    }

    public function getPrivateKey(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_KEY, '', lazy: true);
    }

    /** Returns the installation secret created with the IdP keypair; this read path never writes. */
    public function getPersistentNameIdPepper(): string {
        $pepper = $this->appConfig->getValueString(Application::APP_ID, self::KEY_NAMEID_PEPPER, '', lazy: true);
        if ($pepper === '') {
            throw new \RuntimeException('Persistent NameID pepper is not initialized; generate an IdP certificate first');
        }
        return $pepper;
    }

    /** A usable IdP keypair must be parseable, private, and currently valid. */
    public function hasCertificate(): bool {
        $certificate = $this->getCertificate();
        $privateKey = $this->getPrivateKey();
        if ($certificate === '' || $privateKey === '' || !self::certificateIsCurrentlyValid($certificate)) {
            return false;
        }
        return self::privateKeyMatchesCertificate($privateKey, $certificate);
    }

    /** Validate time window and mandatory signing-only X.509 extensions. */
    public static function certificateIsCurrentlyValid(string $certificate): bool {
        $details = openssl_x509_parse($certificate);
        if (!is_array($details) || !isset($details['validFrom_time_t'], $details['validTo_time_t'])) {
            return false;
        }
        $now = time();
        if ((int)$details['validFrom_time_t'] > $now || (int)$details['validTo_time_t'] <= $now) {
            return false;
        }
        // SP certificates in the wild may not carry these optional extensions.
        // Enforce them only for the app-generated IdP keypair in generateCertificate().
        return true;
    }

    /** Prove that the configured private key corresponds to the configured certificate. */
    private static function privateKeyMatchesCertificate(string $privateKeyPem, string $certificatePem): bool {
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        $publicKey = openssl_pkey_get_public($certificatePem);
        if ($privateKey === false || $publicKey === false) {
            return false;
        }
        $challenge = random_bytes(32);
        $signature = '';
        return openssl_sign($challenge, $signature, $privateKey, OPENSSL_ALGO_SHA256)
            && openssl_verify($challenge, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Generates a self-signed, non-CA 4096-bit RSA signing keypair valid for 10 years. */
    public function generateCertificate(string $commonName): void {
        $configFile = tempnam(sys_get_temp_dir(), 'saml-provider-openssl-');
        if ($configFile === false) {
            throw new \RuntimeException('Could not create OpenSSL configuration');
        }
        $config = <<<CONF
[ req ]
distinguished_name = req_distinguished_name
req_extensions = v3_signing
[ req_distinguished_name ]
[ v3_signing ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
CONF;
        if (file_put_contents($configFile, $config) === false) {
            @unlink($configFile);
            throw new \RuntimeException('Could not write OpenSSL configuration');
        }
        try {
            $dn = ['commonName' => $commonName, 'organizationName' => 'Nextcloud'];
            $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false) {
                throw new \RuntimeException('openssl_pkey_new failed');
            }
            $opensslConfig = ['digest_alg' => 'sha256', 'config' => $configFile, 'req_extensions' => 'v3_signing'];
            $csr = openssl_csr_new($dn, $key, $opensslConfig);
            if ($csr === false) {
                throw new \RuntimeException('openssl_csr_new failed');
            }
            $cert = openssl_csr_sign($csr, null, $key, 3650, $opensslConfig + ['x509_extensions' => 'v3_signing']);
            if ($cert === false) {
                throw new \RuntimeException('openssl_csr_sign failed');
            }
            if (!openssl_x509_export($cert, $certOut)) {
                throw new \RuntimeException('openssl_x509_export failed');
            }
            if (!openssl_pkey_export($key, $keyOut)) {
                throw new \RuntimeException('openssl_pkey_export failed');
            }
            $details = openssl_x509_parse($certOut);
            $extensions = is_array($details) && isset($details['extensions']) && is_array($details['extensions']) ? $details['extensions'] : [];
            if (($extensions['basicConstraints'] ?? '') !== 'CA:FALSE'
                || !str_contains((string)($extensions['keyUsage'] ?? ''), 'Digital Signature')
                || !self::privateKeyMatchesCertificate($keyOut, $certOut)) {
                throw new \RuntimeException('Generated certificate does not meet the signing-key policy');
            }
            // The persistent NameID secret belongs to the IdP identity. Initialize it
            // during explicit certificate setup, never lazily while serving a login.
            if ($this->appConfig->getValueString(Application::APP_ID, self::KEY_NAMEID_PEPPER, '', lazy: true) === '') {
                $this->appConfig->setValueString(Application::APP_ID, self::KEY_NAMEID_PEPPER, base64_encode(random_bytes(32)), lazy: true, sensitive: true);
            }
            $this->appConfig->setValueString(Application::APP_ID, self::KEY_CERT, $certOut);
            $this->appConfig->setValueString(Application::APP_ID, self::KEY_KEY, $keyOut, lazy: true, sensitive: true);
        } finally {
            @unlink($configFile);
        }
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
