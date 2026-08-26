<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Controller;

use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCA\SAMLProvider\Service\IdpConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Settings\ISettings;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {
    private const NAME_ID_FORMATS = [
        'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified',
    ];

    public function __construct(
        string $appName,
        IRequest $request,
        private ServiceProviderMapper $spMapper,
        private IdpConfigService $idpConfig,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(ISettings::class)]
    public function saveIdp(string $orgName): DataResponse {
        $this->idpConfig->setOrgName($orgName);
        return new DataResponse(['orgName' => $orgName]);
    }

    #[AuthorizedAdminSetting(ISettings::class)]
    public function generateCert(): DataResponse {
        $this->idpConfig->generateCertificate($this->idpConfig->getEntityId());
        return new DataResponse(['certificate' => $this->idpConfig->getCertificate()]);
    }

    #[AuthorizedAdminSetting(ISettings::class)]
    public function createSp(
        string $spEntityId,
        string $spName,
        string $acsUrl,
        string $sloUrl = '',
        string $nameIdFormat = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        string $attributeMapping = '{}',
        string $spCertificate = '',
        bool $signAssertions = true,
        bool $requireSignedRequests = false,
    ): DataResponse {
        $error = $this->validateSpInput($spEntityId, $acsUrl, $nameIdFormat, $attributeMapping, $spCertificate, $requireSignedRequests);
        if ($error !== null) {
            return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->spMapper->findByEntityId($spEntityId);
            return new DataResponse(['error' => $this->l->t('A service provider with this Entity ID already exists')], Http::STATUS_CONFLICT);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            // expected
        }

        $sp = new ServiceProvider();
        $sp->setSpEntityId($spEntityId);
        $sp->setSpName($spName);
        $sp->setAcsUrl($acsUrl);
        $sp->setSloUrl($sloUrl !== '' ? $sloUrl : null);
        $sp->setNameIdFormat($nameIdFormat);
        $sp->setAttributeMapping($attributeMapping !== '{}' ? $attributeMapping : null);
        $sp->setSpCertificate($spCertificate !== '' ? $spCertificate : null);
        $sp->setSignAssertions($signAssertions);
        $sp->setRequireSignedRequests($requireSignedRequests);
        $sp->setIsEnabled(true);
        $sp = $this->spMapper->insert($sp);
        return new DataResponse($sp->jsonSerialize(), Http::STATUS_CREATED);
    }

    #[AuthorizedAdminSetting(ISettings::class)]
    public function updateSp(int $id, array $fields): DataResponse {
        try {
            $sp = $this->spMapper->find($id);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => $this->l->t('Service provider not found')], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            // Do not mask database/entity errors as a misleading 404. Logging and
            // rethrowing lets Nextcloud return an appropriate 5xx response and record
            // the original exception for diagnostics.
            $this->logger->error('saml_provider: could not load service provider for update', [
                'id' => $id,
                'exception' => $e,
            ]);
            throw $e;
        }
        foreach (['spName', 'acsUrl', 'sloUrl', 'nameIdFormat', 'attributeMapping', 'spCertificate'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sp->{'set' . ucfirst($key)}((string)$fields[$key]);
            }
        }
        foreach (['signAssertions', 'requireSignedRequests', 'isEnabled'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sp->{'set' . ucfirst($key)}((bool)$fields[$key]);
            }
        }
        return new DataResponse($this->spMapper->update($sp)->jsonSerialize());
    }

    #[AuthorizedAdminSetting(ISettings::class)]
    public function deleteSp(int $id): DataResponse {
        try {
            $this->spMapper->delete($this->spMapper->find($id));
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => $this->l->t('Service provider not found')], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    private function validateSpInput(
        string $spEntityId, string $acsUrl, string $nameIdFormat,
        string $attributeMapping, string $spCertificate, bool $requireSignedRequests,
    ): ?string {
        if (trim($spEntityId) === '') {
            return $this->l->t('Entity ID must not be empty');
        }
        if (!filter_var($acsUrl, FILTER_VALIDATE_URL)) {
            return $this->l->t('ACS URL must be a valid URL');
        }
        $scheme = parse_url($acsUrl, PHP_URL_SCHEME);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return $this->l->t('ACS URL must use http:// or https://');
        }
        // In production (Nextcloud over HTTPS), do not allow cleartext HTTP for Service Providers
        $ncBase = $this->idpConfig->getEntityId();
        if (str_starts_with($ncBase, 'https://') && $scheme === 'http') {
            return $this->l->t('SAML Provider runs on HTTPS; cleartext HTTP is not allowed for service providers in production.');
        }
        if (!in_array($nameIdFormat, self::NAME_ID_FORMATS, true)) {
            return $this->l->t('Unsupported NameID format');
        }
        if (json_decode($attributeMapping) === null && $attributeMapping !== 'null') {
            return $this->l->t('Attribute mapping must be valid JSON');
        }
        if ($spCertificate !== '' && openssl_x509_read($spCertificate) === false) {
            return $this->l->t('SP certificate is not a valid X.509 PEM certificate');
        }
        if ($requireSignedRequests && $spCertificate === '') {
            return $this->l->t('Requiring signed requests needs the SP certificate');
        }
        return null;
    }


}
