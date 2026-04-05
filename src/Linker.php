<?php

declare(strict_types=1);

namespace Origin\Linker;

use Origin\Linker\Exceptions\LinkerException;
use Origin\Linker\Payload\BasePayload;

class Linker
{
    private HttpClientInterface $http;

    public function __construct(
        private readonly Config $config,
        ?HttpClientInterface $http = null
    ) {
        $this->http = $http ?? new HttpClient(
            $config->retryAttempts(),
            $config->retryDelayMs()
        );
    }

    public function sendToParent(BasePayload $payload): Response
    {
        if (!$this->config->isChild()) {
            throw new LinkerException('Cannot call sendToParent(): this config has no parent section.');
        }

        $payload->setSource($this->config->selfDomain());

        return $this->http->post(
            $this->config->parentUrl(),
            $this->buildHeaders($this->config->apiKey()),
            $payload->toArray()
        );
    }

    public function sendToChild(string $slug, BasePayload $payload): Response
    {
        if (!$this->config->isParent()) {
            throw new LinkerException('Cannot call sendToChild(): this config has no children section.');
        }

        $child = $this->config->child($slug); // throws LinkerException if unknown

        $payload->setSource($this->config->selfDomain());

        return $this->http->post(
            $child['url'],
            $this->buildHeaders($this->config->apiKey()),
            $payload->toArray()
        );
    }

    private function buildHeaders(string $apiKey): array
    {
        return [
            'X-Linker-Key'    => $apiKey,
            'X-Linker-Source' => $this->config->selfDomain(),
        ];
    }
}
