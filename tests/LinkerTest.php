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
        $this->assertSame('Not Found', $response->errorMessage());
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
