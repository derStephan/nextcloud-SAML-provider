<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Controller;

use OCA\SAMLProvider\Db\ServiceProvider;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/** User-facing launcher page: lists all enabled SPs for one-click login. */
class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ServiceProviderMapper $spMapper,
        private IInitialState $initialState,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript('saml_provider', 'page');
        Util::addStyle('saml_provider', 'page');

        $sps = array_map(fn(ServiceProvider $sp) => [
            'id'       => $sp->getId(),
            'name'     => $sp->getSpName(),
            'loginUrl' => $this->urlGenerator->linkToRouteAbsolute(
                'saml_provider.saml.idpInitiated', ['spId' => $sp->getId()]
            ),
        ], $this->spMapper->findAllEnabled());

        $this->initialState->provideInitialState('serviceProviders', $sps);
        return new TemplateResponse('saml_provider', 'page/index');
    }
}
