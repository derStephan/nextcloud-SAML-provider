<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\AppInfo;

use OCP\AppFramework\App;

/** Application container for the SAML Provider app. */
class Application extends App {
    public const APP_ID = 'saml_provider';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }
}
