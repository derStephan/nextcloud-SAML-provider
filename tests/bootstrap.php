<?php
declare(strict_types=1);

/*
 * Lightweight OCP test doubles keep unit tests runnable outside a full Nextcloud
 * installation. They model only methods exercised by a test and are not an API
 * authority. tests/Integration/nextcloud-api-contract.php verifies the production
 * OCP surface inside every dynamically selected real Nextcloud Docker version.
 */
namespace OCP {
    interface IAppConfig { public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string; public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool; }
    interface IURLGenerator { public function getAbsoluteURL(string $url): string; public function linkToRouteAbsolute(string $route, array $params = []): string; public function linkTo(string $app, string $file): string; public function getBaseUrl(): string; public function imagePath(string $app, string $file): string; }
    interface IUser { public function getUID(): string; public function getEMailAddress(): ?string; public function getDisplayName(): string; }
}
namespace OCP\AppFramework { class App { public function __construct(string $appName, array $urlParams = []) {} } }
namespace OCP\AppFramework\Db {
    class Entity {
        protected ?int $id = null;
        /** @var array<string, true> */ protected array $updatedFields = [];
        /** @var array<string, string> */ protected array $types = [];
        public function addType(string $field, string $type): void { $this->types[$field] = $type; }
        public function markFieldUpdated(string $field): void { $this->updatedFields[$field] = true; }
        public function __call(string $name, array $arguments): mixed {
            if ($name === 'getId' && $arguments === []) { return $this->id; }
            if ($name === 'setId' && count($arguments) === 1) { $this->id = (int)$arguments[0]; return null; }
            throw new \BadMethodCallException("Unknown entity method: $name");
        }
        /** @return list<string> */ public function getUpdatedFields(): array { return array_keys($this->updatedFields); }
    }
    class QBMapper {
        protected mixed $db;
        public function __construct(mixed $db, string $tableName = '', string $entityClass = '') { $this->db = $db; }
        protected function getTableName(): string { return 'saml_provider_sp'; }
        protected function findEntity(mixed $queryBuilder): mixed { throw new DoesNotExistException('Not found'); }
        protected function findEntities(mixed $queryBuilder): array { return []; }
        public function insert(Entity $entity): Entity { return $entity; }
        public function update(Entity $entity): Entity { return $entity; }
        public function delete(Entity $entity): void {}
    }
    class DoesNotExistException extends \RuntimeException {}
}
namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/Support/TestDoubles.php';
}
namespace OCP {
    interface IDBConnection { public function getQueryBuilder(): mixed; }
    interface IRequest { public function getParam(string $key, mixed $default = null); public function getParams(): array; public function getMethod(): string; }
    interface IUserSession { public function isLoggedIn(): bool; public function getUser(): ?IUser; public function logout(): void; }
    interface IL10N { public function t(string $text, array $parameters = []): string; }
}
namespace OCP\AppFramework { class Controller { protected \OCP\IRequest $request; public function __construct(string $appName, \OCP\IRequest $request) { $this->request=$request; } } }
namespace OCP\AppFramework { class Http { public const STATUS_BAD_REQUEST=400; public const STATUS_NOT_FOUND=404; public const STATUS_UNAUTHORIZED=401; public const STATUS_CREATED=201; public const STATUS_CONFLICT=409; public const STATUS_NO_CONTENT=204; } }
namespace OCP\AppFramework\Http {
    class Response { public function __construct(public int $status=200) {} }
    class DataResponse extends Response { public function __construct(public mixed $data=[], int $status=200) { parent::__construct($status); } }
    class RedirectResponse extends Response { public function __construct(public string $redirectURL) { parent::__construct(302); } }
    class TemplateResponse extends Response { public mixed $contentSecurityPolicy = null; public function __construct(public string $appName, public string $templateName, public array $params=[], public string $renderAs='') { parent::__construct(); } public function setContentSecurityPolicy(mixed $csp): void { $this->contentSecurityPolicy = $csp; } }
    class DataDownloadResponse extends Response { public function __construct(public string $data, public string $filename, public string $contentType) { parent::__construct(); } }
    class ContentSecurityPolicy { public array $domains=[]; public function addAllowedFormActionDomain(string $domain): void { $this->domains[]=$domain; } }
}
namespace OCP\AppFramework\Http\Attribute { #[\Attribute] class AuthorizedAdminSetting { public function __construct(string $type) {} } #[\Attribute] class NoAdminRequired {} #[\Attribute] class NoCSRFRequired {} #[\Attribute] class PublicPage {} }
namespace OCP\AppFramework\Services { interface IInitialState { public function provideInitialState(string $key, mixed $value): void; } }
namespace OCP\Settings { interface ISettings { public function getForm(): \OCP\AppFramework\Http\TemplateResponse; public function getSection(): string; public function getPriority(): int; } interface IIconSection { public function getID(): string; public function getName(): string; public function getPriority(): int; public function getIcon(): string; } }
namespace OCP { class Util { public static function addScript(string $app,string $script): void {} public static function addStyle(string $app,string $style): void {} } }
