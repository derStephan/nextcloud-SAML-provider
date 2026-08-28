<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Controller;

use OCA\SAMLProvider\Service\IdpConfigService;
use OCA\SAMLProvider\Service\SamlService;
use OCA\SAMLProvider\Service\RawQueryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SamlController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private SamlService $samlService,
        private IdpConfigService $idpConfig,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private RawQueryService $rawQuery,
    ) {
        parent::__construct($appName, $request);
    }

    /** Well-known IdP metadata document for SP onboarding. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[AnonRateLimit(limit: 60, period: 60)]
    public function metadata(): Http\Response {
        if (!$this->idpConfig->hasCertificate()) {
            return new Http\Response(Http::STATUS_NOT_FOUND);
        }
        return new DataDownloadResponse(
            $this->samlService->buildMetadataXml(),
            'metadata.xml',
            'application/samlmetadata+xml'
        );
    }

    /**
     * SSO endpoint: receives the AuthnRequest (Redirect or POST binding),
     * enforces the per-SP signature policy, logs the user in via Nextcloud
     * if needed, and returns an auto-submitting POST form with the Response.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function sso(): Http\Response {
        $samlRequest = $this->request->getParam('SAMLRequest');
        $relayState  = $this->request->getParam('RelayState');

        if (!is_string($samlRequest)) {
            return new Http\Response(Http::STATUS_BAD_REQUEST);
        }

        $binding = $this->request->getMethod() === 'GET' ? 'redirect' : 'post';
        try {
            $authnRequest = $this->samlService->parseAuthnRequest($samlRequest, $binding);
            $sp = $this->samlService->resolveServiceProvider($authnRequest['issuer']);
            $this->samlService->enforceNameIdPolicy($authnRequest, $sp);
            $this->samlService->enforceRequestSignature(
                $authnRequest, $binding, $this->request->getParams(), $sp,
                $this->rawQuery->fromRequest($this->request)
            );
        } catch (\Throwable $e) {
            // Do not concatenate attacker-influenced parser details into logs.
            $this->logger->warning('Rejected AuthnRequest', ['app' => 'saml_provider', 'reason' => get_class($e)]);
            return new Http\Response(Http::STATUS_BAD_REQUEST);
        }

        if (!$this->userSession->isLoggedIn()) {
            // The core login redirect target must stay a same-origin path. An
            // absolute URL is resolved again by some supported server versions.
            $ssoUrl = $this->urlGenerator->linkToRouteAbsolute('saml_provider.saml.sso');
            $currentUrl = (string)(parse_url($ssoUrl, PHP_URL_PATH) ?? '/');
            $queryString = $this->rawQuery->fromRequest($this->request);
            if ($queryString !== '') {
                $currentUrl .= '?' . $queryString;
            }
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm', [
                    'redirect_url' => $currentUrl,
                ])
            );
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return new Http\Response(Http::STATUS_UNAUTHORIZED);
        }

        $responseB64 = $this->samlService->buildResponse(
            $sp, $user, $authnRequest['id'], $authnRequest['acsUrl']
        );
        return $this->postFormResponse($sp->getAcsUrl(), $responseB64, is_string($relayState) ? $relayState : null);
    }

    /**
     * IdP-initiated SSO starts with a same-origin confirmation page. A GET request
     * never creates an assertion, preventing third-party login CSRF.
     */
    #[NoAdminRequired]
    public function idpInitiated(int $spId): Http\Response {
        if (!$this->userSession->isLoggedIn()) {
            return new RedirectResponse(
                $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm', [
                    'redirect_url' => $this->urlGenerator->linkToRouteAbsolute(
                        'saml_provider.saml.idpInitiated', ['spId' => $spId]
                    ),
                ])
            );
        }
        try {
            $sp = $this->samlService->resolveServiceProviderById($spId);
        } catch (\Throwable $e) {
            $this->logger->warning('IdP-initiated login rejected for service {id}', ['app' => 'saml_provider', 'id' => $spId]);
            return new Http\Response(Http::STATUS_NOT_FOUND);
        }
        Util::addScript('saml_provider', 'confirm_login');
        return new TemplateResponse('saml_provider', 'page/confirm_login', [
            'spId' => $spId,
            'spName' => $sp->getSpName(),
            'confirmUrl' => $this->urlGenerator->linkToRouteAbsolute('saml_provider.saml.confirmIdpInitiated', ['spId' => $spId]),
        ]);
    }

    /** Creates an unsolicited assertion only after Nextcloud's CSRF middleware accepts the POST. */
    #[NoAdminRequired]
    public function confirmIdpInitiated(int $spId): Http\Response {
        if (!$this->userSession->isLoggedIn()) {
            return new Http\Response(Http::STATUS_UNAUTHORIZED);
        }
        try {
            $sp = $this->samlService->resolveServiceProviderById($spId);
        } catch (\Throwable $e) {
            $this->logger->warning('Confirmed IdP-initiated login rejected for service {id}', ['app' => 'saml_provider', 'id' => $spId]);
            return new Http\Response(Http::STATUS_NOT_FOUND);
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new Http\Response(Http::STATUS_UNAUTHORIZED);
        }
        return $this->postFormResponse($sp->getAcsUrl(), $this->samlService->buildResponse($sp, $user, null, null), null);
    }

    /** Auto-submitting HTML form that POSTs the SAMLResponse to the SP's ACS URL. */
    private function postFormResponse(string $acsUrl, string $samlResponse, ?string $relayState): TemplateResponse {
        $template = new TemplateResponse('saml_provider', 'post_response', [
            'acsUrl'       => $acsUrl,
            'samlResponse' => $samlResponse,
            'relayState'   => $relayState,
            'scriptUrl'    => $this->urlGenerator->linkTo('saml_provider', 'js/post_response.js'),
        ], 'blank');
        $csp = new ContentSecurityPolicy();
        // Nextcloud 34 validates this API argument as a host source, not a full
        // URL. Preserve an explicit non-default port because CSP form-action matches
        // ports; omit only the scheme and path, which Nextcloud 34 rejects here.
        $acsHost = parse_url($acsUrl, PHP_URL_HOST);
        $acsPort = parse_url($acsUrl, PHP_URL_PORT);
        if (is_string($acsHost) && $acsHost !== '') {
            $acsCspHost = $acsHost;
            if (is_int($acsPort) && $acsPort > 0 && $acsPort <= 65535) {
                $acsCspHost .= ':' . $acsPort;
            }
            $csp->addAllowedFormActionDomain($acsCspHost);
        }
        $template->setContentSecurityPolicy($csp);
        return $template;
    }

}
