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
