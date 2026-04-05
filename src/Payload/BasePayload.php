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
