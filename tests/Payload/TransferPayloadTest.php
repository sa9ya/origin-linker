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
