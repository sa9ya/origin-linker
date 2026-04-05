<?php

declare(strict_types=1);

namespace Origin\Linker;

use Origin\Linker\Exceptions\LinkerException;

class Config
{
    private function __construct(private readonly array $config) {}

    public static function fromArray(array $config): self
    {
        if (empty($config['api_key'])) {
            throw new LinkerException('Config must contain "api_key".');
        }
        return new self($config);
    }

    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new LinkerException("Config file not found: {$path}");
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new LinkerException("Config file must return an array.");
        }
        return self::fromArray($data);
    }

    public function apiKey(): string
    {
        return $this->config['api_key'];
    }

    public function isParent(): bool
    {
        return isset($this->config['children']);
    }

    public function isChild(): bool
    {
        return isset($this->config['parent']);
    }

    public function parentUrl(): string
    {
        $this->assertIsChild();
        return $this->config['parent']['url'];
    }

    public function parentApiKey(): string
    {
        $this->assertIsChild();
        return $this->config['parent']['api_key'];
    }

    public function child(string $slug): array
    {
        if (!isset($this->config['children'][$slug])) {
            throw new LinkerException("Unknown child project: \"{$slug}\".");
        }
        return $this->config['children'][$slug];
    }

    public function allChildApiKeys(): array
    {
        $this->assertIsParent();
        return array_values(array_column($this->config['children'], 'api_key'));
    }

    public function childByApiKey(string $apiKey): array
    {
        $this->assertIsParent();
        foreach ($this->config['children'] as $child) {
            if ($child['api_key'] === $apiKey) {
                return $child;
            }
        }
        throw new LinkerException("No child found with the given API key.");
    }

    public function selfDomain(): string
    {
        return (string) ($this->config['domain'] ?? '');
    }

    public function retryAttempts(): int
    {
        return (int) ($this->config['retry']['attempts'] ?? 3);
    }

    public function retryDelayMs(): int
    {
        return (int) ($this->config['retry']['delay_ms'] ?? 500);
    }

    private function assertIsChild(): void
    {
        if (!$this->isChild()) {
            throw new LinkerException('This config has no parent section.');
        }
    }

    private function assertIsParent(): void
    {
        if (!$this->isParent()) {
            throw new LinkerException('This config has no children section.');
        }
    }
}
