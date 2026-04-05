<?php

declare(strict_types=1);

namespace Origin\Linker;

interface HttpClientInterface
{
    /**
     * Send a POST request with JSON body and headers.
     * Returns Response — either ok or error.
     */
    public function post(string $url, array $headers, array $body): Response;
}
