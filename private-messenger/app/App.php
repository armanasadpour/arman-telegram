<?php
declare(strict_types=1);

namespace PM;

use PM\Infra\Database;
use PM\Settings\Settings;
use PM\Support\Env;

/**
 * Minimal service container. Everything is created lazily so that the stealth
 * 404 path never touches the database.
 */
final class App
{
    private ?Database $db = null;
    private ?Settings $settings = null;
    /** @var array<string, string> */
    private array $env;

    private function __construct(public readonly string $root, array $env)
    {
        $this->env = $env;
        ini_set('error_log', $root . '/storage/logs/php-error.log');
    }

    public static function boot(string $root): self
    {
        $root = rtrim($root, '/');
        $envFile = $root . '/config/.env';
        $env = is_file($envFile) ? Env::parseFile($envFile) : [];
        return new self($root, $env);
    }

    public function isInstalled(): bool
    {
        return is_file($this->root . '/storage/installed.lock') && isset($this->env['APP_KEY'], $this->env['APP_PATH'], $this->env['ADMIN_PATH']);
    }

    public function env(string $key, ?string $default = null): ?string
    {
        return $this->env[$key] ?? $default;
    }

    /** Raw 32-byte application key (HMAC pepper, secretbox key). */
    public function key(): string
    {
        $k = base64_decode((string)($this->env['APP_KEY'] ?? ''), true);
        if ($k === false || strlen($k) !== 32) {
            throw new \RuntimeException('APP_KEY missing or invalid');
        }
        return $k;
    }

    public function db(): Database
    {
        return $this->db ??= Database::connect(
            (string)$this->env('DB_HOST', 'localhost'),
            (int)$this->env('DB_PORT', '3306'),
            (string)$this->env('DB_NAME', ''),
            (string)$this->env('DB_USER', ''),
            (string)$this->env('DB_PASS', '')
        );
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db(), $this->key());
    }

    /** For tests and the installer. */
    public function withEnv(array $env): self
    {
        return new self($this->root, $env);
    }

    public function setDatabase(Database $db): void
    {
        $this->db = $db;
        $this->settings = null;
    }
}
