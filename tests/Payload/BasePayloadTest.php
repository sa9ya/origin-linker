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
