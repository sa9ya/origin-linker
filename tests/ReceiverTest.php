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
