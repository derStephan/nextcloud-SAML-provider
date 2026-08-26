<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class ServiceProvider extends Entity implements JsonSerializable {
    protected string $spEntityId = '';
    protected string $spName = '';
    protected string $acsUrl = '';
    protected ?string $sloUrl = null;
    protected ?string $spCertificate = null;
    protected string $nameIdFormat = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';
    protected ?string $attributeMapping = null;
    protected bool $signAssertions = true;
    protected bool $requireSignedRequests = false;
    protected bool $isEnabled = true;

    public function __construct() {
        $this->addType('signAssertions', 'boolean');
        $this->addType('requireSignedRequests', 'boolean');
        $this->addType('isEnabled', 'boolean');
    }

    public function getSpEntityId(): string {
        return $this->spEntityId;
    }

    public function setSpEntityId(string $spEntityId): void {
        $this->spEntityId = $spEntityId;
        $this->markFieldUpdated('spEntityId');
    }

    public function getSpName(): string {
        return $this->spName;
    }

    public function setSpName(string $spName): void {
        $this->spName = $spName;
        $this->markFieldUpdated('spName');
    }

    public function getAcsUrl(): string {
        return $this->acsUrl;
    }

    public function setAcsUrl(string $acsUrl): void {
        $this->acsUrl = $acsUrl;
        $this->markFieldUpdated('acsUrl');
    }

    public function getSloUrl(): string {
        return $this->sloUrl ?? '';
    }

    public function setSloUrl(?string $sloUrl): void {
        $this->sloUrl = $sloUrl;
        $this->markFieldUpdated('sloUrl');
    }

    public function getSpCertificate(): string {
        return $this->spCertificate ?? '';
    }

    public function setSpCertificate(?string $spCertificate): void {
        $this->spCertificate = $spCertificate;
        $this->markFieldUpdated('spCertificate');
    }

    public function getNameIdFormat(): string {
        return $this->nameIdFormat;
    }

    public function setNameIdFormat(string $nameIdFormat): void {
        $this->nameIdFormat = $nameIdFormat;
        $this->markFieldUpdated('nameIdFormat');
    }

    public function getAttributeMapping(): string {
        $val = $this->attributeMapping ?? '{}';
        return $val === '' ? '{}' : $val;
    }

    public function setAttributeMapping(?string $attributeMapping): void {
        $this->attributeMapping = $attributeMapping;
        $this->markFieldUpdated('attributeMapping');
    }

    public function getSignAssertions(): bool {
        return $this->signAssertions;
    }

    public function setSignAssertions(bool $signAssertions): void {
        $this->signAssertions = $signAssertions;
        $this->markFieldUpdated('signAssertions');
    }

    public function getRequireSignedRequests(): bool {
        return $this->requireSignedRequests;
    }

    public function setRequireSignedRequests(bool $requireSignedRequests): void {
        $this->requireSignedRequests = $requireSignedRequests;
        $this->markFieldUpdated('requireSignedRequests');
    }

    public function getIsEnabled(): bool {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): void {
        $this->isEnabled = $isEnabled;
        $this->markFieldUpdated('isEnabled');
    }

    public function jsonSerialize(): array {
        return [
            'id'                    => $this->getId(),
            'spEntityId'            => $this->getSpEntityId(),
            'spName'                => $this->getSpName(),
            'acsUrl'                => $this->getAcsUrl(),
            'sloUrl'                => $this->getSloUrl(),
            'spCertificate'         => $this->getSpCertificate(),
            'nameIdFormat'          => $this->getNameIdFormat(),
            'attributeMapping'      => $this->getAttributeMapping(),
            'signAssertions'        => $this->getSignAssertions(),
            'requireSignedRequests' => $this->getRequireSignedRequests(),
            'isEnabled'             => $this->getIsEnabled(),
        ];
    }
}
