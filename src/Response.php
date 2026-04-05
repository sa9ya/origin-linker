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

    public static function __callStatic(string $name, array $arguments): self
    {
        if ($name === 'error') {
            return new self(false, $arguments[0], $arguments[1]);
        }
        throw new \BadMethodCallException("Static method $name does not exist");
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function statusCode(): int
    {
        return $this->code;
    }

    public function __call(string $name, array $arguments): ?string
    {
        if ($name === 'error') {
            return $this->ok ? null : $this->message;
        }
        throw new \BadMethodCallException("Method $name does not exist");
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
