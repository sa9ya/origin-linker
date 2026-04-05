<?php

declare(strict_types=1);

namespace Origin\Linker\Tests;

use Origin\Linker\Config;
use Origin\Linker\Exceptions\LinkerException;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private array $parentConfig = [
        'api_key'  => 'parent-key',
        'children' => [
            'cleaning' => [
                'url'     => 'https://cleaning.example.com/linker',
                'api_key' => 'child-cleaning-key',
                'domain'  => 'cleaning.example.com',
            ],
        ],
        'retry' => ['attempts' => 3, 'delay_ms' => 500],
    ];

    private array $childConfig = [
        'api_key' => 'child-key',
        'domain'  => 'cleaning.example.com',
        'parent'  => [
            'url'     => 'https://main.example.com/linker',
            'api_key' => 'parent-key',
        ],
        'retry' => ['attempts' => 3, 'delay_ms' => 500],
    ];

    public function test_from_array_parent(): void
    {
        $config = Config::fromArray($this->parentConfig);
        $this->assertSame('parent-key', $config->apiKey());
        $this->assertTrue($config->isParent());
        $this->assertFalse($config->isChild());
    }

    public function test_from_array_child(): void
    {
        $config = Config::fromArray($this->childConfig);
        $this->assertSame('child-key', $config->apiKey());
        $this->assertTrue($config->isChild());
        $this->assertFalse($config->isParent());
    }

    public function test_parent_url(): void
    {
        $config = Config::fromArray($this->childConfig);
        $this->assertSame('https://main.example.com/linker', $config->parentUrl());
        $this->assertSame('parent-key', $config->parentApiKey());
    }

    public function test_child_config(): void
    {
        $config = Config::fromArray($this->parentConfig);
        $child = $config->child('cleaning');
        $this->assertSame('https://cleaning.example.com/linker', $child['url']);
        $this->assertSame('child-cleaning-key', $child['api_key']);
        $this->assertSame('cleaning.example.com', $child['domain']);
    }

    public function test_unknown_child_throws(): void
    {
        $config = Config::fromArray($this->parentConfig);
        $this->expectException(LinkerException::class);
        $config->child('unknown');
    }

    public function test_retry_config(): void
    {
        $config = Config::fromArray($this->parentConfig);
        $this->assertSame(3, $config->retryAttempts());
        $this->assertSame(500, $config->retryDelayMs());
    }

    public function test_missing_api_key_throws(): void
    {
        $this->expectException(LinkerException::class);
        Config::fromArray([]);
    }

    public function test_all_child_api_keys(): void
    {
        $config = Config::fromArray($this->parentConfig);
        $this->assertSame(['child-cleaning-key'], $config->allChildApiKeys());
    }

    public function test_child_by_api_key(): void
    {
        $config = Config::fromArray($this->parentConfig);
        $child = $config->childByApiKey('child-cleaning-key');
        $this->assertSame('cleaning.example.com', $child['domain']);
    }

    public function test_self_domain_from_child_config(): void
    {
        $config = Config::fromArray($this->childConfig);
        $this->assertSame('cleaning.example.com', $config->selfDomain());
    }

    public function test_from_file(): void
    {
        $path = sys_get_temp_dir() . '/linker_test_config.php';
        file_put_contents($path, '<?php return ' . var_export($this->childConfig, true) . ';');
        $config = Config::fromFile($path);
        $this->assertSame('child-key', $config->apiKey());
        unlink($path);
    }
}
