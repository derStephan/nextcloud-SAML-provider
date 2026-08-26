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
    public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): void { $this->values[$key] = $value; $this->writeOptions[$key] = ['lazy' => $lazy, 'sensitive' => $sensitive]; }
}
final class UrlGenerator implements IURLGenerator { public function __construct(private string $base = 'https://cloud.example.test') {} public function getAbsoluteURL(string $url): string { return $this->base . $url; } }
final class User implements IUser { public function __construct(private string $uid = 'alice', private ?string $mail = 'alice@example.test', private string $name = 'Alice Example') {} public function getUID(): string { return $this->uid; } public function getEMailAddress(): ?string { return $this->mail; } public function getDisplayName(): string { return $this->name; } }
final class NullLogger implements LoggerInterface { public function emergency(\Stringable|string $message, array $context = []): void {} public function alert(\Stringable|string $message, array $context = []): void {} public function critical(\Stringable|string $message, array $context = []): void {} public function error(\Stringable|string $message, array $context = []): void {} public function warning(\Stringable|string $message, array $context = []): void {} public function notice(\Stringable|string $message, array $context = []): void {} public function info(\Stringable|string $message, array $context = []): void {} public function debug(\Stringable|string $message, array $context = []): void {} public function log($level, \Stringable|string $message, array $context = []): void {} }
