<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l,
    ) {}

    public function getID(): string {
        return 'saml_provider';
    }

    public function getName(): string {
        return $this->l->t('SAML Provider');
    }

    public function getPriority(): int {
        return 50;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('saml_provider', 'app-dark.svg');
    }
}
