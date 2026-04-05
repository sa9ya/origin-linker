<?php

declare(strict_types=1);

namespace Origin\Linker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class HttpClient implements HttpClientInterface
{
    private Client $guzzle;

    public function __construct(private readonly int $attempts, private readonly int $delayMs)
    {
        $this->guzzle = new Client(['timeout' => 10.0, 'connect_timeout' => 5.0]);
    }

    public function post(string $url, array $headers, array $body): Response
    {
        $lastError = '';
        $triesLeft = max(1, $this->attempts);

        for ($attempt = 1; $attempt <= $triesLeft; $attempt++) {
            try {
                $guzzleResponse = $this->guzzle->post($url, [
                    'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
                    'json'    => $body,
                ]);

                $code = $guzzleResponse->getStatusCode();
                $decoded = json_decode((string) $guzzleResponse->getBody(), true) ?? [];

                return Response::ok(
                    $code,
                    (string) ($decoded['message'] ?? 'ok'),
                    (array) ($decoded['data'] ?? [])
                );

            } catch (ConnectException $e) {
                // Network-level error — eligible for retry
                $lastError = $e->getMessage();
                if ($attempt < $triesLeft) {
                    usleep($this->delayMs * 1000);
                }
            } catch (RequestException $e) {
                // HTTP error response (4xx, 5xx) — no retry
                $code = $e->getResponse()?->getStatusCode() ?? 0;
                $errorBody = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : [];
                $message = (string) ($errorBody['message'] ?? $e->getMessage());
                return Response::error($code, $message);
            }
        }

        return Response::error(0, "Network error after {$this->attempts} attempt(s): {$lastError}");
    }
}
