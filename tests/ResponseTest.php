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
        $this->assertNull($response->errorMessage());
        $this->assertSame(['status' => 'ok', 'message' => 'Success', 'data' => []], $response->toArray());
    }

    public function test_error_response(): void
    {
        $response = Response::error(500, 'Internal error');
        $this->assertFalse($response->isOk());
        $this->assertSame(500, $response->statusCode());
        $this->assertSame('Internal error', $response->errorMessage());
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
