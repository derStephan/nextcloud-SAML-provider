<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Controller;

use OCA\SAMLProvider\Service\IdpConfigService;
use OCA\SAMLProvider\Service\SamlService;
use OCA\SAMLProvider\Db\ServiceProviderMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
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
        private ServiceProviderMapper $spMapper,
    ) {
        parent::__construct($appName, $request);
    }

    /** Well-known IdP metadata document for SP onboarding. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[NoAdminRequired]
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
            $this->samlService->enforceRequestSignature(
                $authnRequest, $binding, $this->request->getParams(), $sp,
                (string)$this->request->getServerParam('QUERY_STRING', '')
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Rejected AuthnRequest: ' . $e->getMessage(), ['app' => 'saml_provider']);
            return new Http\Response(Http::STATUS_BAD_REQUEST);
        }

        if (!$this->userSession->isLoggedIn()) {
            // Bounce through Nextcloud's own login, then come back here
            $currentUrl = $this->urlGenerator->linkToRouteAbsolute('saml_provider.saml.sso')
                . '?' . ($_SERVER['QUERY_STRING'] ?? '');
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

    /** IdP-initiated SSO: posts an unsolicited Response to the SP. */
    #[NoAdminRequired]
    #[NoCSRFRequired]
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
            $this->logger->warning('saml_provider: idpInitiated failed to resolve SP #{id}: {msg}', [
                'id' => $spId, 'msg' => $e->getMessage(), 'exception' => $e,
            ]);
            return new Http\Response(Http::STATUS_NOT_FOUND);
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new Http\Response(Http::STATUS_UNAUTHORIZED);
        }
        $responseB64 = $this->samlService->buildResponse($sp, $user, null, null);
        return $this->postFormResponse($sp->getAcsUrl(), $responseB64, null);
    }

    /** Single Logout: ends the Nextcloud session, redirects back to the SP. */
    #[PublicPage]
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function slo(): RedirectResponse {
        $relayState = $this->request->getParam('RelayState');
        if ($this->userSession->isLoggedIn()) {
            $this->userSession->logout();
        }
        
        // Open-Redirect Mitigation: Verify RelayState (Redirect Target)
        if (is_string($relayState) && $this->isSafeRedirectUrl($relayState)) {
            return new RedirectResponse($relayState);
        }
        
        return new RedirectResponse($this->urlGenerator->getBaseUrl());
    }

    /** Auto-submitting HTML form that POSTs the SAMLResponse to the SP's ACS URL. */
    private function postFormResponse(string $acsUrl, string $samlResponse, ?string $relayState): TemplateResponse {
        $template = new TemplateResponse('saml_provider', 'post_response', [
            'acsUrl'       => $acsUrl,
            'samlResponse' => $samlResponse,
            'relayState'   => $relayState,
            'scriptUrl'    => $this->urlGenerator->linkTo('saml_provider', 'js/post_response.js'),
            // Nextcloud 33 uses a strict-dynamic CSP. External scripts must carry
            // the nonce generated for this exact response.
            'cspNonce'    => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
        ], 'blank');
        $csp = new ContentSecurityPolicy();
        $acsHost = parse_url($acsUrl, PHP_URL_HOST);
        if (is_string($acsHost) && $acsHost !== '') {
            // ContentSecurityPolicy accepts a host/domain here, not the full ACS URL.
            $csp->addAllowedFormActionDomain($acsHost);
        }
        $template->setContentSecurityPolicy($csp);
        return $template;
    }

    /**
     * Prevents Open Redirect Vulnerabilities.
     * Allows redirect only to local Nextcloud paths or domains of registered Service Providers.
     */
    private function isSafeRedirectUrl(string $url): bool {
        // Local path
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $targetHost = parse_url($url, PHP_URL_HOST);
        if ($targetHost === null) {
            return false;
        }

        // Compare with Nextcloud Host
        $ncBase = $this->urlGenerator->getAbsoluteURL('/');
        $ncHost = parse_url($ncBase, PHP_URL_HOST);
        if ($ncHost !== null && strcasecmp($targetHost, $ncHost) === 0) {
            return true;
        }

        // Compare with all registered Service Providers ACS/SLO hosts
        $sps = $this->spMapper->findAllEnabled();
        foreach ($sps as $sp) {
            $spHost = parse_url($sp->getAcsUrl(), PHP_URL_HOST);
            if ($spHost !== null && strcasecmp($targetHost, $spHost) === 0) {
                return true;
            }
            if ($sp->getSloUrl() !== '') {
                $spSloHost = parse_url($sp->getSloUrl(), PHP_URL_HOST);
                if ($spSloHost !== null && strcasecmp($targetHost, $spSloHost) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
