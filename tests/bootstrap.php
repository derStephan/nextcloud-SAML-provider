<?php
declare(strict_types=1);

/*
 * Lightweight OCP test doubles keep these unit tests runnable outside a full
 * Nextcloud installation. Production code still uses the real OCP interfaces.
 */
namespace OCP {
    interface IAppConfig { public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string; public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): void; }
    interface IURLGenerator { public function getAbsoluteURL(string $url): string; }
    interface IUser { public function getUID(): string; public function getEMailAddress(): ?string; public function getDisplayName(): string; }
}
namespace OCP\AppFramework { class App { public function __construct(string $appName, array $urlParams = []) {} } }
namespace OCP\AppFramework\Bootstrap { interface IBootstrap {} interface IRegistrationContext {} interface IBootContext {} }
namespace OCP\AppFramework\Db {
    class Entity {
        protected ?int $id = null;
        /** @var array<string, true> */ protected array $updatedFields = [];
        /** @var array<string, string> */ protected array $types = [];
        public function addType(string $field, string $type): void { $this->types[$field] = $type; }
        public function markFieldUpdated(string $field): void { $this->updatedFields[$field] = true; }
        public function getId(): ?int { return $this->id; }
        public function setId(int $id): void { $this->id = $id; }
        /** @return list<string> */ public function getUpdatedFields(): array { return array_keys($this->updatedFields); }
    }
    class QBMapper {}
    class DoesNotExistException extends \RuntimeException {}
}
namespace OCA\SAMLProvider\Db {
    class ServiceProviderMapper {
        public ?ServiceProvider $byEntityId = null;
        public ?ServiceProvider $byId = null;
        public bool $requiresSignedRequests = false;
        public function findByEntityId(string $entityId): ServiceProvider { if ($this->byEntityId === null) { throw new \RuntimeException('Not found'); } return $this->byEntityId; }
        public function find(int $id): ServiceProvider { if ($this->byId === null) { throw new \RuntimeException('Not found'); } return $this->byId; }
        public function anyRequiresSignedRequests(): bool { return $this->requiresSignedRequests; }
    }
}
namespace { require_once __DIR__ . '/../vendor/autoload.php'; }
