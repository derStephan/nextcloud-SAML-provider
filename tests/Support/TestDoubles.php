<?php
declare(strict_types=1);

namespace OCA\SAMLProvider\Tests\Support;

use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use Psr\Log\LoggerInterface;

final class AppConfig implements IAppConfig {
    /** @var array<string, string> */ public array $values = [];
    /** @var array<string, array{lazy:bool,sensitive:bool}> */ public array $writeOptions = [];
    public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string { return $this->values[$key] ?? $default; }
    public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool { $this->values[$key] = $value; $this->writeOptions[$key] = ['lazy' => $lazy, 'sensitive' => $sensitive]; return true; }
}
class UrlGenerator implements IURLGenerator {
    public function __construct(protected string $base = 'https://cloud.example.test') {}
    public function getAbsoluteURL(string $url): string { return $this->base . $url; }
    public function linkToRouteAbsolute(string $route, array $params = []): string { return $this->base . '/' . $route; }
    public function linkTo(string $app, string $file): string { return '/apps/' . $app . '/' . $file; }
    public function getBaseUrl(): string { return '/'; }
    public function imagePath(string $app, string $file): string { return '/apps/' . $app . '/img/' . $file; }
}
final class User implements IUser { public function __construct(private string $uid = 'alice', private ?string $mail = 'alice@example.test', private string $name = 'Alice Example') {} public function getUID(): string { return $this->uid; } public function getEMailAddress(): ?string { return $this->mail; } public function getDisplayName(): string { return $this->name; } }
final class NullLogger implements LoggerInterface { public function emergency(\Stringable|string $message, array $context = []): void {} public function alert(\Stringable|string $message, array $context = []): void {} public function critical(\Stringable|string $message, array $context = []): void {} public function error(\Stringable|string $message, array $context = []): void {} public function warning(\Stringable|string $message, array $context = []): void {} public function notice(\Stringable|string $message, array $context = []): void {} public function info(\Stringable|string $message, array $context = []): void {} public function debug(\Stringable|string $message, array $context = []): void {} public function log($level, \Stringable|string $message, array $context = []): void {} }
namespace OCA\SAMLProvider\Tests\Support;
final class Request implements \OCP\IRequest { public function __construct(public array $params=[], public string $method='GET'){} public function getParam(string $key,mixed $default=null){return $this->params[$key]??$default;} public function getParams():array{return $this->params;} public function getMethod():string{return $this->method;} }
final class Session implements \OCP\IUserSession { public bool $loggedIn=false; public bool $loggedOut=false; public function __construct(public ?\OCP\IUser $user=null){} public function isLoggedIn():bool{return $this->loggedIn;} public function getUser():?\OCP\IUser{return $this->user;} public function logout():void{$this->loggedOut=true;$this->loggedIn=false;} }
final class L10N implements \OCP\IL10N { public function t(string $text,array $parameters=[]):string{return $text;} }
final class InitialState implements \OCP\AppFramework\Services\IInitialState { public array $values=[]; public function provideInitialState(string $key,mixed $value):void{$this->values[$key]=$value;} }
final class RouteUrlGenerator extends UrlGenerator {
    public function linkToRouteAbsolute(string $route, array $params = []): string {
        $url = 'https://cloud.example.test/' . $route;
        // The app's parameterised IdP route is represented as a path in this fixture.
        if (isset($params['spId'])) {
            $url .= '/' . rawurlencode((string)$params['spId']);
            unset($params['spId']);
        }
        // Preserve all remaining route parameters. Controller tests must observe the
        // same redirect_url propagation that Nextcloud receives in production.
        if ($params !== []) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }
    public function linkTo(string $app, string $file): string { return '/apps/' . $app . '/' . $file; }
    public function getBaseUrl(): string { return '/'; }
}

final class TestServiceProviderMapper extends \OCA\SAMLProvider\Db\ServiceProviderMapper {
    public ?\OCA\SAMLProvider\Db\ServiceProvider $byEntityId = null;
    public ?\OCA\SAMLProvider\Db\ServiceProvider $byId = null;
    public bool $requiresSignedRequests = false;
    /** @var list<\OCA\SAMLProvider\Db\ServiceProvider> */ public array $enabled = [];
    /** @var list<\OCA\SAMLProvider\Db\ServiceProvider> */ public array $rows = [];
    public bool $failUpdate = false;
    public function __construct() {}
    public function findByEntityId(string $entityId): \OCA\SAMLProvider\Db\ServiceProvider { if ($this->byEntityId === null) throw new \OCP\AppFramework\Db\DoesNotExistException('Not found'); return $this->byEntityId; }
    public function find(int $id): \OCA\SAMLProvider\Db\ServiceProvider { if ($this->byId === null) throw new \OCP\AppFramework\Db\DoesNotExistException('Not found'); return $this->byId; }
    public function findAll(): array { return $this->rows; }
    public function findAllEnabled(): array { return $this->enabled; }
    public function insert(\OCP\AppFramework\Db\Entity $sp): \OCP\AppFramework\Db\Entity { $sp->setId(count($this->rows) + 1); $this->rows[] = $sp; $this->byEntityId = $sp; $this->byId = $sp; return $sp; }
    public function update(\OCP\AppFramework\Db\Entity $sp): \OCP\AppFramework\Db\Entity { if ($this->failUpdate) throw new \RuntimeException('simulated database failure'); $this->byId = $sp; return $sp; }
    public function delete(\OCP\AppFramework\Db\Entity $sp): void { $this->byId = null; }
    public function anyRequiresSignedRequests(): bool { return $this->requiresSignedRequests; }
}
