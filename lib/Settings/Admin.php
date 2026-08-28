<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Settings;

use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCA\SAMLProvider\Service\IdpConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
    private const NAME_ID_FORMAT_LABELS = [
        'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress' => 'E-Mail address of the user (most common)',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'   => 'Anonymous, permanent ID (privacy-friendly)',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified'  => 'Nextcloud username',
    ];

    public function __construct(
        private IdpConfigService $idpConfig,
        private ServiceProviderMapper $spMapper,
        private IInitialState $initialState,
    ) {}

    public function getForm(): TemplateResponse {
        Util::addScript('saml_provider', 'settings');
        Util::addStyle('saml_provider', 'settings');

        $this->initialState->provideInitialState('idp', [
            'entityId'             => $this->idpConfig->getEntityId(),
            'ssoUrl'               => $this->idpConfig->getSsoUrl(),
            'hasCertificate'       => $this->idpConfig->hasCertificate(),
            'certificate'          => $this->idpConfig->getCertificate(),
            'certificateSingleLine' => IdpConfigService::pemToBase64($this->idpConfig->getCertificate()),
        ]);
        $this->initialState->provideInitialState(
            'serviceProviders',
            array_map(fn(ServiceProvider $sp) => $sp->jsonSerialize(), $this->spMapper->findAll())
        );
        $this->initialState->provideInitialState('help', [
            'nameIdFormats' => self::NAME_ID_FORMAT_LABELS,
            'attributeFields' => [
                'uid'         => 'Nextcloud username (login name)',
                'displayName' => 'Full (display) name',
                'mail'        => 'E-Mail address',
            ],
        ]);
        return new TemplateResponse('saml_provider', 'settings/index');
    }

    public function getSection(): string {
        return 'saml_provider';
    }

    public function getPriority(): int {
        return 50;
    }
}
