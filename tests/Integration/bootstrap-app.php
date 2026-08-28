<?php
declare(strict_types=1);

/**
 * Bootstrap for standalone integration contracts executed with `php <script>`
 * inside a real Nextcloud container. `occ app:enable` loads the app for HTTP
 * requests, but direct CLI script execution does not guarantee registration of
 * this app's namespace. Register only this app's PSR-4 namespace after Nextcloud
 * itself is initialized; all OCP types and database services remain Nextcloud's.
 */
$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require_once $nextcloudRoot . '/lib/base.php';
$appRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($appRoot): void {
    $prefix = 'OCA\\SAMLProvider\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $appRoot . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
