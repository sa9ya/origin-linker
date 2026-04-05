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
