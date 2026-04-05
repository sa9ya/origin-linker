# Origin Linker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a framework-agnostic PHP Composer library that enables bidirectional HTTP communication between a parent project and multiple child projects with API-key authentication and typed service payloads.

**Architecture:** A thin HTTP client library with three core roles — `Linker` (sends requests), `Receiver` (validates incoming requests), and `Config` (holds project topology). Payloads are typed per service category with a shared base structure. Retry logic applies only to network-level failures; HTTP 4xx/5xx are returned immediately as errors.

**Tech Stack:** PHP 8.0+, Guzzle 7, PHPUnit 10

---

## File Map

| File | Responsibility |
|---|---|
| `composer.json` | Package definition, autoloading, dependencies |
| `phpunit.xml` | PHPUnit configuration |
| `src/Exceptions/LinkerException.php` | Base exception for invalid usage (bad payload, missing config) |
| `src/Exceptions/AuthException.php` | Auth failures (wrong key, forbidden domain) |
| `src/Response.php` | Immutable result object returned by Linker and Receiver |
| `src/Config.php` | Parses and holds project config (api_key, parent, children, retry) |
| `src/Payload/BasePayload.php` | Abstract base: category, source, timestamp, uuid, data; validates required fields |
| `src/Payload/CleaningPayload.php` | Cleaning-specific fields: area, bedrooms, bathrooms, rooms, deep_clean |
| `src/Payload/TransferPayload.php` | Transfer-specific fields: pickup, dropoff, trip_type, waypoints, passengers, luggage, flight_no |
| `src/HttpClientInterface.php` | Interface for HTTP transport (enables MockHttpClient) |
| `src/HttpClient.php` | Guzzle-backed implementation with network-level retry |
| `src/MockHttpClient.php` | Test double — returns preset response without HTTP |
| `src/Receiver.php` | Validates incoming request headers + body, returns Response |
| `src/Linker.php` | Sends payloads to parent or child, enforces config role |
| `tests/ConfigTest.php` | Unit tests for Config parsing and validation |
| `tests/ResponseTest.php` | Unit tests for Response object |
| `tests/Payload/BasePayloadTest.php` | Unit tests for BasePayload serialization and UUID generation |
| `tests/Payload/CleaningPayloadTest.php` | Unit tests for CleaningPayload required field validation |
| `tests/Payload/TransferPayloadTest.php` | Unit tests for TransferPayload required field validation |
| `tests/ReceiverTest.php` | Unit tests for Receiver auth and parsing |
| `tests/LinkerTest.php` | Unit tests for Linker routing and error handling |

---

## Task 1: Project Scaffold

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `src/.gitkeep`, `tests/.gitkeep` (directories)

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "origin/linker",
    "description": "Framework-agnostic PHP library for connecting parent and child projects via HTTP",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.0",
        "guzzlehttp/guzzle": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "Origin\\Linker\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Origin\\Linker\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Origin Linker Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Create directory structure**

```bash
mkdir -p src/Payload src/Exceptions tests/Payload
```

- [ ] **Step 4: Install dependencies**

```bash
composer install
```

Expected: `vendor/` created, `vendor/autoload.php` exists.

- [ ] **Step 5: Commit**

```bash
git add composer.json phpunit.xml
git commit -m "chore: scaffold composer package with phpunit"
```

---

## Task 2: Exceptions

**Files:**
- Create: `src/Exceptions/LinkerException.php`
- Create: `src/Exceptions/AuthException.php`

- [ ] **Step 1: Create LinkerException**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Exceptions;

use RuntimeException;

class LinkerException extends RuntimeException {}
```

- [ ] **Step 2: Create AuthException**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Exceptions;

class AuthException extends LinkerException {}
```

- [ ] **Step 3: Verify autoloading**

```bash
php -r "require 'vendor/autoload.php'; new Origin\Linker\Exceptions\LinkerException('ok'); echo 'ok';"
```

Expected output: `ok`

- [ ] **Step 4: Commit**

```bash
git add src/Exceptions/LinkerException.php src/Exceptions/AuthException.php
git commit -m "feat: add LinkerException and AuthException"
```

---

## Task 3: Response

**Files:**
- Create: `src/Response.php`
- Create: `tests/ResponseTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Tests;

use Origin\Linker\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function test_ok_response(): void
    {
        $response = Response::ok(200, 'Success');
        $this->assertTrue($response->isOk());
        $this->assertSame(200, $response->statusCode());
        $this->assertNull($response->error());
        $this->assertSame(['status' => 'ok', 'message' => 'Success', 'data' => []], $response->toArray());
    }

    public function test_error_response(): void
    {
        $response = Response::error(500, 'Internal error');
        $this->assertFalse($response->isOk());
        $this->assertSame(500, $response->statusCode());
        $this->assertSame('Internal error', $response->error());
        $this->assertSame(['status' => 'error', 'message' => 'Internal error', 'data' => []], $response->toArray());
    }

    public function test_response_with_data(): void
    {
        $response = Response::ok(200, 'ok', ['order_id' => 42]);
        $this->assertSame(['status' => 'ok', 'message' => 'ok', 'data' => ['order_id' => 42]], $response->toArray());
    }

    public function test_payload_is_null_by_default(): void
    {
        $response = Response::ok(200, 'ok');
        $this->assertNull($response->payload());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/ResponseTest.php
```

Expected: FAIL — `Class "Origin\Linker\Response" not found`

- [ ] **Step 3: Create Response.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker;

use Origin\Linker\Payload\BasePayload;

class Response
{
    private function __construct(
        private readonly bool         $ok,
        private readonly int          $code,
        private readonly string       $message,
        private readonly array        $data = [],
        private readonly ?BasePayload $payloadObject = null,
    ) {}

    public static function ok(int $code, string $message, array $data = [], ?BasePayload $payload = null): self
    {
        return new self(true, $code, $message, $data, $payload);
    }

    public static function error(int $code, string $message): self
    {
        return new self(false, $code, $message);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function statusCode(): int
    {
        return $this->code;
    }

    public function error(): ?string
    {
        return $this->ok ? null : $this->message;
    }

    public function payload(): ?BasePayload
    {
        return $this->payloadObject;
    }

    public function toArray(): array
    {
        return [
            'status'  => $this->ok ? 'ok' : 'error',
            'message' => $this->message,
            'data'    => $this->data,
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/ResponseTest.php
```

Expected: 4 tests, 4 assertions, OK

- [ ] **Step 5: Commit**

```bash
git add src/Response.php tests/ResponseTest.php
git commit -m "feat: add Response value object"
```

---

## Task 4: Config

**Files:**
- Create: `src/Config.php`
- Create: `tests/ConfigTest.php`

- [ ] **Step 1: Write the failing tests**

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/ConfigTest.php
```

Expected: FAIL — `Class "Origin\Linker\Config" not found`

- [ ] **Step 3: Create Config.php**

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/ConfigTest.php
```

Expected: 10 tests, OK

- [ ] **Step 5: Commit**

```bash
git add src/Config.php tests/ConfigTest.php
git commit -m "feat: add Config with parent/child role detection"
```

---

## Task 5: BasePayload

**Files:**
- Create: `src/Payload/BasePayload.php`
- Create: `tests/Payload/BasePayloadTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Tests\Payload;

use Origin\Linker\Payload\BasePayload;
use Origin\Linker\Exceptions\LinkerException;
use PHPUnit\Framework\TestCase;

class BasePayloadTest extends TestCase
{
    private function makeConcretePayload(array $data = []): BasePayload
    {
        return new class($data) extends BasePayload {
            protected string $category = 'test';
            protected array $requiredFields = ['name'];
        };
    }

    public function test_creates_with_required_fields(): void
    {
        $payload = $this->makeConcretePayload(['name' => 'Test Order']);
        $this->assertSame('test', $payload->category());
        $this->assertSame(['name' => 'Test Order'], $payload->data());
    }

    public function test_auto_generates_uuid(): void
    {
        $payload = $this->makeConcretePayload(['name' => 'Order']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload->uuid()
        );
    }

    public function test_auto_sets_timestamp(): void
    {
        $before = time();
        $payload = $this->makeConcretePayload(['name' => 'Order']);
        $after = time();
        $this->assertGreaterThanOrEqual($before, $payload->timestamp());
        $this->assertLessThanOrEqual($after, $payload->timestamp());
    }

    public function test_missing_required_field_throws(): void
    {
        $this->expectException(LinkerException::class);
        $this->expectExceptionMessage('"name"');
        $this->makeConcretePayload([]);
    }

    public function test_to_array_includes_all_fields(): void
    {
        $payload = $this->makeConcretePayload(['name' => 'Order']);
        $arr = $payload->toArray();
        $this->assertArrayHasKey('category', $arr);
        $this->assertArrayHasKey('uuid', $arr);
        $this->assertArrayHasKey('timestamp', $arr);
        $this->assertArrayHasKey('source', $arr);
        $this->assertArrayHasKey('data', $arr);
        $this->assertSame('test', $arr['category']);
        $this->assertSame(['name' => 'Order'], $arr['data']);
    }

    public function test_source_can_be_set(): void
    {
        $payload = $this->makeConcretePayload(['name' => 'Order']);
        $payload->setSource('cleaning.example.com');
        $this->assertSame('cleaning.example.com', $payload->source());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Payload/BasePayloadTest.php
```

Expected: FAIL — `Class "Origin\Linker\Payload\BasePayload" not found`

- [ ] **Step 3: Create BasePayload.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Payload;

use Origin\Linker\Exceptions\LinkerException;

abstract class BasePayload
{
    protected string $category = '';
    protected array $requiredFields = [];

    private string $uuid;
    private int $timestamp;
    private string $source = '';

    public function __construct(private array $data)
    {
        $this->validateRequiredFields();
        $this->uuid = $this->generateUuid();
        $this->timestamp = time();
    }

    public function category(): string
    {
        return $this->category;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function timestamp(): int
    {
        return $this->timestamp;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return [
            'category'  => $this->category,
            'uuid'      => $this->uuid,
            'timestamp' => $this->timestamp,
            'source'    => $this->source,
            'data'      => $this->data,
        ];
    }

    public static function fromArray(array $arr): static
    {
        $instance = new static($arr['data'] ?? []);
        $instance->uuid = $arr['uuid'] ?? $instance->uuid;
        $instance->timestamp = $arr['timestamp'] ?? $instance->timestamp;
        $instance->source = $arr['source'] ?? '';
        return $instance;
    }

    private function validateRequiredFields(): void
    {
        foreach ($this->requiredFields as $field) {
            if (!array_key_exists($field, $this->data)) {
                throw new LinkerException("Payload is missing required field: \"{$field}\".");
            }
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Payload/BasePayloadTest.php
```

Expected: 6 tests, OK

- [ ] **Step 5: Commit**

```bash
git add src/Payload/BasePayload.php tests/Payload/BasePayloadTest.php
git commit -m "feat: add BasePayload with UUID generation and field validation"
```

---

## Task 6: CleaningPayload

**Files:**
- Create: `src/Payload/CleaningPayload.php`
- Create: `tests/Payload/CleaningPayloadTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Tests\Payload;

use Origin\Linker\Payload\CleaningPayload;
use Origin\Linker\Exceptions\LinkerException;
use PHPUnit\Framework\TestCase;

class CleaningPayloadTest extends TestCase
{
    private array $valid = [
        'area'       => 120,
        'bedrooms'   => 3,
        'bathrooms'  => 2,
        'rooms'      => ['kitchen', 'living_room'],
        'deep_clean' => true,
    ];

    public function test_creates_with_valid_data(): void
    {
        $payload = new CleaningPayload($this->valid);
        $this->assertSame('cleaning', $payload->category());
        $this->assertSame(120, $payload->data()['area']);
        $this->assertSame(['kitchen', 'living_room'], $payload->data()['rooms']);
    }

    public function test_missing_area_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['area']);
        new CleaningPayload($data);
    }

    public function test_missing_bedrooms_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['bedrooms']);
        new CleaningPayload($data);
    }

    public function test_missing_bathrooms_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['bathrooms']);
        new CleaningPayload($data);
    }

    public function test_missing_rooms_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['rooms']);
        new CleaningPayload($data);
    }

    public function test_missing_deep_clean_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['deep_clean']);
        new CleaningPayload($data);
    }

    public function test_to_array_round_trip(): void
    {
        $original = new CleaningPayload($this->valid);
        $restored = CleaningPayload::fromArray($original->toArray());
        $this->assertSame('cleaning', $restored->category());
        $this->assertSame($original->data(), $restored->data());
        $this->assertSame($original->uuid(), $restored->uuid());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Payload/CleaningPayloadTest.php
```

Expected: FAIL — `Class "Origin\Linker\Payload\CleaningPayload" not found`

- [ ] **Step 3: Create CleaningPayload.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Payload;

class CleaningPayload extends BasePayload
{
    protected string $category = 'cleaning';
    protected array $requiredFields = ['area', 'bedrooms', 'bathrooms', 'rooms', 'deep_clean'];
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Payload/CleaningPayloadTest.php
```

Expected: 7 tests, OK

- [ ] **Step 5: Commit**

```bash
git add src/Payload/CleaningPayload.php tests/Payload/CleaningPayloadTest.php
git commit -m "feat: add CleaningPayload"
```

---

## Task 7: TransferPayload

**Files:**
- Create: `src/Payload/TransferPayload.php`
- Create: `tests/Payload/TransferPayloadTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Tests\Payload;

use Origin\Linker\Payload\TransferPayload;
use Origin\Linker\Exceptions\LinkerException;
use PHPUnit\Framework\TestCase;

class TransferPayloadTest extends TestCase
{
    private array $valid = [
        'pickup'    => 'Kyiv, Khreshchatyk 1',
        'dropoff'   => 'Boryspil Airport',
        'trip_type' => 'to_airport',
    ];

    public function test_creates_with_minimal_required_data(): void
    {
        $payload = new TransferPayload($this->valid);
        $this->assertSame('transfer', $payload->category());
        $this->assertSame('to_airport', $payload->data()['trip_type']);
    }

    public function test_creates_with_all_optional_fields(): void
    {
        $data = array_merge($this->valid, [
            'waypoints'  => ['Stop A'],
            'passengers' => 2,
            'luggage'    => 1,
            'flight_no'  => 'PS101',
        ]);
        $payload = new TransferPayload($data);
        $this->assertSame('PS101', $payload->data()['flight_no']);
        $this->assertSame(2, $payload->data()['passengers']);
    }

    public function test_missing_pickup_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['pickup']);
        new TransferPayload($data);
    }

    public function test_missing_dropoff_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['dropoff']);
        new TransferPayload($data);
    }

    public function test_missing_trip_type_throws(): void
    {
        $this->expectException(LinkerException::class);
        $data = $this->valid;
        unset($data['trip_type']);
        new TransferPayload($data);
    }

    public function test_to_array_round_trip(): void
    {
        $original = new TransferPayload($this->valid);
        $restored = TransferPayload::fromArray($original->toArray());
        $this->assertSame('transfer', $restored->category());
        $this->assertSame($original->data(), $restored->data());
        $this->assertSame($original->uuid(), $restored->uuid());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Payload/TransferPayloadTest.php
```

Expected: FAIL — `Class "Origin\Linker\Payload\TransferPayload" not found`

- [ ] **Step 3: Create TransferPayload.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Payload;

class TransferPayload extends BasePayload
{
    protected string $category = 'transfer';
    protected array $requiredFields = ['pickup', 'dropoff', 'trip_type'];
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Payload/TransferPayloadTest.php
```

Expected: 6 tests, OK

- [ ] **Step 5: Commit**

```bash
git add src/Payload/TransferPayload.php tests/Payload/TransferPayloadTest.php
git commit -m "feat: add TransferPayload"
```

---

## Task 8: HttpClientInterface and MockHttpClient

**Files:**
- Create: `src/HttpClientInterface.php`
- Create: `src/MockHttpClient.php`

- [ ] **Step 1: Create HttpClientInterface.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker;

interface HttpClientInterface
{
    /**
     * Send a POST request with JSON body and headers.
     * Returns Response — either ok or error.
     */
    public function post(string $url, array $headers, array $body): Response;
}
```

- [ ] **Step 2: Create MockHttpClient.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker;

class MockHttpClient implements HttpClientInterface
{
    /** @var array List of queued responses [{status, message, code}] */
    private array $queue = [];

    public function __construct(array $response = [])
    {
        if (!empty($response)) {
            $this->queue[] = $response;
        }
    }

    public function addResponse(array $response): void
    {
        $this->queue[] = $response;
    }

    public function post(string $url, array $headers, array $body): Response
    {
        $preset = array_shift($this->queue) ?? ['status' => 'ok', 'message' => 'mock ok', 'code' => 200];

        $code = (int) ($preset['code'] ?? 200);
        $message = (string) ($preset['message'] ?? '');

        if (($preset['status'] ?? 'ok') === 'ok') {
            return Response::ok($code, $message);
        }

        return Response::error($code, $message);
    }
}
```

- [ ] **Step 3: Verify both classes load**

```bash
php -r "require 'vendor/autoload.php'; new Origin\Linker\MockHttpClient(); echo 'ok';"
```

Expected output: `ok`

- [ ] **Step 4: Commit**

```bash
git add src/HttpClientInterface.php src/MockHttpClient.php
git commit -m "feat: add HttpClientInterface and MockHttpClient for testing"
```

---

## Task 9: HttpClient (Guzzle)

**Files:**
- Create: `src/HttpClient.php`

- [ ] **Step 1: Create HttpClient.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class HttpClient implements HttpClientInterface
{
    private Client $guzzle;

    public function __construct(private readonly int $attempts, private readonly int $delayMs)
    {
        $this->guzzle = new Client(['timeout' => 10.0, 'connect_timeout' => 5.0]);
    }

    public function post(string $url, array $headers, array $body): Response
    {
        $lastError = '';
        $triesLeft = max(1, $this->attempts);

        for ($attempt = 1; $attempt <= $triesLeft; $attempt++) {
            try {
                $guzzleResponse = $this->guzzle->post($url, [
                    'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
                    'json'    => $body,
                ]);

                $code = $guzzleResponse->getStatusCode();
                $decoded = json_decode((string) $guzzleResponse->getBody(), true) ?? [];

                return Response::ok(
                    $code,
                    (string) ($decoded['message'] ?? 'ok'),
                    (array) ($decoded['data'] ?? [])
                );

            } catch (ConnectException $e) {
                // Network-level error — eligible for retry
                $lastError = $e->getMessage();
                if ($attempt < $triesLeft) {
                    usleep($this->delayMs * 1000);
                }
            } catch (RequestException $e) {
                // HTTP error response (4xx, 5xx) — no retry
                $code = $e->getResponse()?->getStatusCode() ?? 0;
                $body = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : [];
                $message = (string) ($body['message'] ?? $e->getMessage());
                return Response::error($code, $message);
            }
        }

        return Response::error(0, "Network error after {$this->attempts} attempt(s): {$lastError}");
    }
}
```

- [ ] **Step 2: Verify class loads**

```bash
php -r "require 'vendor/autoload.php'; new Origin\Linker\HttpClient(3, 500); echo 'ok';"
```

Expected output: `ok`

- [ ] **Step 3: Commit**

```bash
git add src/HttpClient.php
git commit -m "feat: add HttpClient with Guzzle and network-level retry"
```

---

## Task 10: Receiver

**Files:**
- Create: `src/Receiver.php`
- Create: `tests/ReceiverTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Tests;

use Origin\Linker\Config;
use Origin\Linker\Payload\CleaningPayload;
use Origin\Linker\Receiver;
use PHPUnit\Framework\TestCase;

class ReceiverTest extends TestCase
{
    private Config $parentConfig;
    private Config $childConfig;

    protected function setUp(): void
    {
        $this->parentConfig = Config::fromArray([
            'api_key'  => 'parent-key',
            'children' => [
                'cleaning' => [
                    'url'     => 'https://cleaning.example.com/linker',
                    'api_key' => 'child-cleaning-key',
                    'domain'  => 'cleaning.example.com',
                ],
            ],
            'retry' => ['attempts' => 3, 'delay_ms' => 500],
        ]);

        $this->childConfig = Config::fromArray([
            'api_key' => 'child-key',
            'parent'  => [
                'url'     => 'https://main.example.com/linker',
                'api_key' => 'parent-key',
            ],
            'retry' => ['attempts' => 3, 'delay_ms' => 500],
        ]);
    }

    private function makeBody(string $category = 'cleaning'): string
    {
        $payload = $category === 'cleaning'
            ? new CleaningPayload(['area' => 50, 'bedrooms' => 1, 'bathrooms' => 1, 'rooms' => ['kitchen'], 'deep_clean' => false])
            : new \Origin\Linker\Payload\TransferPayload(['pickup' => 'A', 'dropoff' => 'B', 'trip_type' => 'point_to_point']);

        return json_encode($payload->toArray());
    }

    public function test_parent_receiver_accepts_valid_child_request(): void
    {
        $receiver = new Receiver($this->parentConfig);
        $response = $receiver->handle(
            ['X-Linker-Key' => 'child-cleaning-key', 'X-Linker-Source' => 'cleaning.example.com'],
            $this->makeBody('cleaning')
        );
        $this->assertTrue($response->isOk());
        $this->assertSame(200, $response->statusCode());
    }

    public function test_parent_receiver_rejects_wrong_api_key(): void
    {
        $receiver = new Receiver($this->parentConfig);
        $response = $receiver->handle(
            ['X-Linker-Key' => 'wrong-key', 'X-Linker-Source' => 'cleaning.example.com'],
            $this->makeBody()
        );
        $this->assertFalse($response->isOk());
        $this->assertSame(401, $response->statusCode());
    }

    public function test_parent_receiver_rejects_wrong_domain(): void
    {
        $receiver = new Receiver($this->parentConfig);
        $response = $receiver->handle(
            ['X-Linker-Key' => 'child-cleaning-key', 'X-Linker-Source' => 'evil.example.com'],
            $this->makeBody()
        );
        $this->assertFalse($response->isOk());
        $this->assertSame(403, $response->statusCode());
    }

    public function test_child_receiver_accepts_valid_parent_request(): void
    {
        $receiver = new Receiver($this->childConfig);
        $response = $receiver->handle(
            ['X-Linker-Key' => 'parent-key', 'X-Linker-Source' => 'main.example.com'],
            $this->makeBody('cleaning')
        );
        $this->assertTrue($response->isOk());
    }

    public function test_child_receiver_rejects_wrong_api_key(): void
    {
        $receiver = new Receiver($this->childConfig);
        $response = $receiver->handle(
            ['X-Linker-Key' => 'bad-key', 'X-Linker-Source' => 'main.example.com'],
            $this->makeBody()
        );
        $this->assertFalse($response->isOk());
        $this->assertSame(401, $response->statusCode());
    }

    public function test_receiver_returns_payload_on_success(): void
    {
        $receiver = new Receiver($this->parentConfig);
        $response = $receiver->handle(
            ['X-Linker-Key' => 'child-cleaning-key', 'X-Linker-Source' => 'cleaning.example.com'],
            $this->makeBody('cleaning')
        );
        $this->assertInstanceOf(CleaningPayload::class, $response->payload());
    }

    public function test_receiver_returns_400_for_invalid_json(): void
    {
        $receiver = new Receiver($this->parentConfig);
        $response = $receiver->handle(
            ['X-Linker-Key' => 'child-cleaning-key', 'X-Linker-Source' => 'cleaning.example.com'],
            'not-json'
        );
        $this->assertFalse($response->isOk());
        $this->assertSame(400, $response->statusCode());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/ReceiverTest.php
```

Expected: FAIL — `Class "Origin\Linker\Receiver" not found`

- [ ] **Step 3: Create Receiver.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker;

use Origin\Linker\Payload\BasePayload;
use Origin\Linker\Payload\CleaningPayload;
use Origin\Linker\Payload\TransferPayload;

class Receiver
{
    private array $payloadMap = [
        'cleaning' => CleaningPayload::class,
        'transfer' => TransferPayload::class,
    ];

    public function __construct(private readonly Config $config) {}

    public function handle(array $headers, string $body): Response
    {
        // Normalize header keys to lowercase for case-insensitive lookup
        $headers = array_change_key_case($headers, CASE_LOWER);

        $apiKey = $headers['x-linker-key'] ?? '';
        $source = $headers['x-linker-source'] ?? '';

        // Authenticate
        if ($this->config->isParent()) {
            $authResult = $this->authenticateAsParent($apiKey, $source);
        } else {
            $authResult = $this->authenticateAsChild($apiKey);
        }

        if ($authResult !== null) {
            return $authResult;
        }

        // Parse body
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return Response::error(400, 'Invalid JSON body.');
        }

        // Resolve payload class
        $category = $data['category'] ?? '';
        $class = $this->payloadMap[$category] ?? null;
        if ($class === null) {
            return Response::error(400, "Unknown payload category: \"{$category}\".");
        }

        try {
            /** @var BasePayload $payload */
            $payload = $class::fromArray($data);
        } catch (\Throwable $e) {
            return Response::error(400, $e->getMessage());
        }

        return Response::ok(200, 'received', [], $payload);
    }

    private function authenticateAsParent(string $apiKey, string $source): ?Response
    {
        try {
            $allowedKeys = $this->config->allChildApiKeys();
        } catch (\Throwable) {
            return Response::error(500, 'Server configuration error.');
        }

        if (!in_array($apiKey, $allowedKeys, true)) {
            return Response::error(401, 'Unauthorized.');
        }

        try {
            $child = $this->config->childByApiKey($apiKey);
        } catch (\Throwable) {
            return Response::error(401, 'Unauthorized.');
        }

        if ($child['domain'] !== $source) {
            return Response::error(403, 'Forbidden.');
        }

        return null;
    }

    private function authenticateAsChild(string $apiKey): ?Response
    {
        if ($this->config->parentApiKey() !== $apiKey) {
            return Response::error(401, 'Unauthorized.');
        }
        return null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/ReceiverTest.php
```

Expected: 7 tests, OK

- [ ] **Step 5: Commit**

```bash
git add src/Receiver.php tests/ReceiverTest.php
git commit -m "feat: add Receiver with auth and payload parsing"
```

---

## Task 11: Linker

**Files:**
- Create: `src/Linker.php`
- Create: `tests/LinkerTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker\Tests;

use Origin\Linker\Config;
use Origin\Linker\Linker;
use Origin\Linker\MockHttpClient;
use Origin\Linker\Payload\CleaningPayload;
use Origin\Linker\Payload\TransferPayload;
use Origin\Linker\Exceptions\LinkerException;
use PHPUnit\Framework\TestCase;

class LinkerTest extends TestCase
{
    private Config $parentConfig;
    private Config $childConfig;
    private CleaningPayload $cleaningPayload;

    protected function setUp(): void
    {
        $this->parentConfig = Config::fromArray([
            'api_key'  => 'parent-key',
            'children' => [
                'cleaning' => [
                    'url'     => 'https://cleaning.example.com/linker',
                    'api_key' => 'child-cleaning-key',
                    'domain'  => 'cleaning.example.com',
                ],
            ],
            'retry' => ['attempts' => 3, 'delay_ms' => 500],
        ]);

        $this->childConfig = Config::fromArray([
            'api_key' => 'child-cleaning-key',
            'domain'  => 'cleaning.example.com',
            'parent'  => [
                'url'     => 'https://main.example.com/linker',
                'api_key' => 'parent-key',
            ],
            'retry' => ['attempts' => 3, 'delay_ms' => 500],
        ]);

        $this->cleaningPayload = new CleaningPayload([
            'area' => 80, 'bedrooms' => 2, 'bathrooms' => 1,
            'rooms' => ['hall'], 'deep_clean' => false,
        ]);
    }

    public function test_child_sends_to_parent_successfully(): void
    {
        $mock = new MockHttpClient(['status' => 'ok', 'message' => 'received', 'code' => 200]);
        $linker = new Linker($this->childConfig, $mock);
        $response = $linker->sendToParent($this->cleaningPayload);
        $this->assertTrue($response->isOk());
        $this->assertSame(200, $response->statusCode());
    }

    public function test_parent_sends_to_child_successfully(): void
    {
        $mock = new MockHttpClient(['status' => 'ok', 'message' => 'received', 'code' => 200]);
        $linker = new Linker($this->parentConfig, $mock);
        $response = $linker->sendToChild('cleaning', $this->cleaningPayload);
        $this->assertTrue($response->isOk());
    }

    public function test_send_to_parent_throws_when_no_parent_config(): void
    {
        $mock = new MockHttpClient();
        $linker = new Linker($this->parentConfig, $mock);
        $this->expectException(LinkerException::class);
        $linker->sendToParent($this->cleaningPayload);
    }

    public function test_send_to_child_throws_when_no_children_config(): void
    {
        $mock = new MockHttpClient();
        $linker = new Linker($this->childConfig, $mock);
        $this->expectException(LinkerException::class);
        $linker->sendToChild('cleaning', $this->cleaningPayload);
    }

    public function test_send_to_unknown_child_throws(): void
    {
        $mock = new MockHttpClient();
        $linker = new Linker($this->parentConfig, $mock);
        $this->expectException(LinkerException::class);
        $linker->sendToChild('unknown', $this->cleaningPayload);
    }

    public function test_http_error_response_returned_as_error(): void
    {
        $mock = new MockHttpClient(['status' => 'error', 'message' => 'Not Found', 'code' => 404]);
        $linker = new Linker($this->childConfig, $mock);
        $response = $linker->sendToParent($this->cleaningPayload);
        $this->assertFalse($response->isOk());
        $this->assertSame(404, $response->statusCode());
        $this->assertSame('Not Found', $response->error());
    }

    public function test_sets_source_domain_on_payload_before_sending(): void
    {
        $mock = new MockHttpClient(['status' => 'ok', 'message' => 'ok', 'code' => 200]);
        $linker = new Linker($this->childConfig, $mock);
        $response = $linker->sendToParent($this->cleaningPayload);
        $this->assertTrue($response->isOk());
        // payload source is set to the sender's api_key before sending
        $this->assertSame('child-cleaning-key', $this->cleaningPayload->source());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/LinkerTest.php
```

Expected: FAIL — `Class "Origin\Linker\Linker" not found`

- [ ] **Step 3: Create Linker.php**

```php
<?php

declare(strict_types=1);

namespace Origin\Linker;

use Origin\Linker\Exceptions\LinkerException;
use Origin\Linker\Payload\BasePayload;

class Linker
{
    private HttpClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ?HttpClientInterface $http = null
    ) {
        $this->http = $http ?? new HttpClient(
            $config->retryAttempts(),
            $config->retryDelayMs()
        );
    }

    public function sendToParent(BasePayload $payload): Response
    {
        if (!$this->config->isChild()) {
            throw new LinkerException('Cannot call sendToParent(): this config has no parent section.');
        }

        $payload->setSource($this->config->apiKey());

        return $this->http->post(
            $this->config->parentUrl(),
            $this->buildHeaders($this->config->apiKey()),
            $payload->toArray()
        );
    }

    public function sendToChild(string $slug, BasePayload $payload): Response
    {
        if (!$this->config->isParent()) {
            throw new LinkerException('Cannot call sendToChild(): this config has no children section.');
        }

        $child = $this->config->child($slug); // throws LinkerException if unknown

        $payload->setSource($this->config->apiKey());

        return $this->http->post(
            $child['url'],
            $this->buildHeaders($this->config->apiKey()),
            $payload->toArray()
        );
    }

    private function buildHeaders(string $apiKey): array
    {
        return [
            'X-Linker-Key'    => $apiKey,
            'X-Linker-Source' => $this->config->selfDomain(),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/LinkerTest.php
```

Expected: 7 tests, OK

- [ ] **Step 5: Run the full test suite**

```bash
./vendor/bin/phpunit
```

Expected: All tests pass, 0 failures.

- [ ] **Step 6: Commit**

```bash
git add src/Linker.php tests/LinkerTest.php
git commit -m "feat: add Linker — send payloads to parent or child projects"
```

---

## Task 12: Final Verification

- [ ] **Step 1: Run full test suite one final time**

```bash
./vendor/bin/phpunit --testdox
```

Expected: All test classes listed, 0 failures, 0 errors.

- [ ] **Step 2: Verify package loads cleanly**

```bash
php -r "
require 'vendor/autoload.php';
use Origin\Linker\Linker;
use Origin\Linker\Receiver;
use Origin\Linker\Config;
use Origin\Linker\MockHttpClient;
use Origin\Linker\Payload\CleaningPayload;

\$config = Config::fromArray([
    'api_key' => 'child-key',
    'domain'  => 'cleaning.test',
    'parent'  => ['url' => 'https://main.test/linker', 'api_key' => 'parent-key'],
    'retry'   => ['attempts' => 2, 'delay_ms' => 100],
]);
\$linker = new Linker(\$config, new MockHttpClient(['status' => 'ok', 'message' => 'ok', 'code' => 200]));
\$response = \$linker->sendToParent(new CleaningPayload([
    'area' => 100, 'bedrooms' => 2, 'bathrooms' => 1, 'rooms' => ['kitchen'], 'deep_clean' => false,
]));
echo \$response->isOk() ? 'PASS' : 'FAIL';
"
```

Expected output: `PASS`

- [ ] **Step 3: Final commit**

```bash
git add -A
git commit -m "chore: final verification — all tests pass"
```
