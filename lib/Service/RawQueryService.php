<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Service;

/**
 * Provides the untouched query string mandated by SAML HTTP-Redirect binding.
 * Nextcloud's public IRequest interface exposes decoded parameters only; it does
 * not expose their original byte representation. This is the sole controlled
 * PHP-server boundary in the application.
 */
final class RawQueryService {
    public function current(): string {
        $query = $_SERVER['QUERY_STRING'] ?? '';
        return is_string($query) ? $query : '';
    }
}
