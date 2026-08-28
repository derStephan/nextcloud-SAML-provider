<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Service;

use OCP\IRequest;

/**
 * Provides the untouched query string required by SAML HTTP-Redirect signing.
 * Keeping this boundary here prevents controllers from reaching into PHP globals.
 */
final class RawQueryService {
    public function fromRequest(IRequest $request): string {
        $query = $request->getServerParam('QUERY_STRING', '');
        return is_string($query) ? $query : '';
    }
}
