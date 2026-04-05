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
