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
                isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING'])
                    ? $_SERVER['QUERY_STRING']
                    : ''
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Rejected AuthnRequest: ' . $e->getMessage(), ['app' => 'saml_provider']);
            return new Http\Response(Http::STATUS_BAD_REQUEST);
        }

        if (!$this->userSession->isLoggedIn()) {
            // The core login redirect target must stay a same-origin path. An
            // absolute URL is resolved again by some supported server versions.
            $ssoUrl = $this->urlGenerator->linkToRouteAbsolute('saml_provider.saml.sso');
            $currentUrl = (string)(parse_url($ssoUrl, PHP_URL_PATH) ?? '/');
            $queryString = isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING'])
                ? $_SERVER['QUERY_STRING']
                : '';
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

    /**
     * Prevents open redirects in RelayState.
     *
     * A relative path stays on this Nextcloud instance. Absolute URLs must have the
     * exact same origin (scheme, host, and effective port) as Nextcloud or a registered
     * ACS/SLO endpoint. Comparing only a host would allow an HTTPS-to-HTTP downgrade or
     * a redirect to a different service listening on the same host and another port.
     */
    private function isSafeRedirectUrl(string $url): bool {
        // Reject control characters and browser-ambiguous backslash paths up front.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\')) {
            return true;
        }

        $targetOrigin = $this->originOf($url);
        if ($targetOrigin === null) {
            return false;
        }
        if ($targetOrigin === $this->originOf($this->urlGenerator->getAbsoluteURL('/'))) {
            return true;
        }

        foreach ($this->spMapper->findAllEnabled() as $sp) {
            if ($targetOrigin === $this->originOf($sp->getAcsUrl())) {
                return true;
            }
            $sloUrl = $sp->getSloUrl();
            if ($sloUrl !== null && $sloUrl !== '' && $targetOrigin === $this->originOf($sloUrl)) {
                return true;
            }
        }
        return false;
    }

    /** @return string|null Normalized http(s) origin, including its effective port. */
    private function originOf(string $url): ?string {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }
        return $scheme . '://' . $host . ':' . $port;
    }
}
