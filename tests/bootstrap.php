<?php
declare(strict_types=1);

/*
 * Lightweight OCP test doubles keep these unit tests runnable outside a full
 * Nextcloud installation. Production code still uses the real OCP interfaces.
 */
namespace OCP {
    interface IAppConfig { public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string; public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): void; }
    interface IURLGenerator { public function getAbsoluteURL(string $url): string; public function linkToRouteAbsolute(string $route, array $params = []): string; public function linkTo(string $app, string $file): string; public function getBaseUrl(): string; public function imagePath(string $app, string $file): string; }
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
        public bool $requiresSignedRequests = false; public array $enabled=[]; public array $rows=[];
        public function findByEntityId(string $entityId): ServiceProvider { if ($this->byEntityId === null) { throw new \OCP\AppFramework\Db\DoesNotExistException('Not found'); } return $this->byEntityId; }
        public function find(int $id): ServiceProvider { if ($this->byId === null) { throw new \OCP\AppFramework\Db\DoesNotExistException('Not found'); } return $this->byId; }
        public function findAll(): array { return $this->rows; }
        public function findAllEnabled(): array { return $this->enabled; }
        public function insert(ServiceProvider $sp): ServiceProvider { $sp->setId(count($this->rows)+1); $this->rows[]=$sp; $this->byEntityId=$sp; $this->byId=$sp; return $sp; }
        public function update(ServiceProvider $sp): ServiceProvider { $this->byId=$sp; return $sp; }
        public function delete(ServiceProvider $sp): void { $this->byId=null; }
        public function anyRequiresSignedRequests(): bool { return $this->requiresSignedRequests; }
    }
}
namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/Support/TestDoubles.php';
}
namespace OCP {
    interface IRequest { public function getParam(string $key): mixed; public function getParams(): array; public function getMethod(): string; public function getServerParam(string $key, mixed $default = null): mixed; }
    interface IUserSession { public function isLoggedIn(): bool; public function getUser(): ?IUser; public function logout(): void; }
    interface IL10N { public function t(string $text, array $parameters = []): string; }
}
namespace OCP\AppFramework { class Controller { protected \OCP\IRequest $request; public function __construct(string $appName, \OCP\IRequest $request) { $this->request=$request; } } }
namespace OCP\AppFramework { class Http { public const STATUS_BAD_REQUEST=400; public const STATUS_NOT_FOUND=404; public const STATUS_UNAUTHORIZED=401; public const STATUS_CREATED=201; public const STATUS_CONFLICT=409; public const STATUS_NO_CONTENT=204; } }
namespace OCP\AppFramework\Http {
    class Response { public function __construct(public int $status=200) {} }
    class DataResponse extends Response { public function __construct(public mixed $data=[], int $status=200) { parent::__construct($status); } }
    class RedirectResponse extends Response { public function __construct(public string $redirectURL) { parent::__construct(302); } }
    class TemplateResponse extends Response { public function __construct(public string $appName, public string $templateName, public array $params=[], public string $renderAs='') { parent::__construct(); } public function setContentSecurityPolicy(mixed $csp): void {} }
    class DataDownloadResponse extends Response { public function __construct(public string $data, public string $filename, public string $contentType) { parent::__construct(); } }
    class ContentSecurityPolicy { public array $domains=[]; public function addAllowedFormActionDomain(string $domain): void { $this->domains[]=$domain; } }
}
namespace OCP\AppFramework\Http\Attribute { #[\Attribute] class AuthorizedAdminSetting { public function __construct(string $type) {} } #[\Attribute] class NoAdminRequired {} #[\Attribute] class NoCSRFRequired {} #[\Attribute] class PublicPage {} }
namespace OCP\AppFramework\Services { interface IInitialState { public function provideInitialState(string $key, mixed $value): void; } }
namespace OCP\Settings { interface ISettings { public function getForm(): \OCP\AppFramework\Http\TemplateResponse; public function getSection(): string; public function getPriority(): int; } interface IIconSection { public function getID(): string; public function getName(): string; public function getPriority(): int; public function getIcon(): string; } }
namespace OCP { class Util { public static function addScript(string $app,string $script): void {} public static function addStyle(string $app,string $style): void {} } }
namespace { class OC { public static object $server; } }
