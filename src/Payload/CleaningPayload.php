<?php

declare(strict_types=1);

namespace Origin\Linker\Payload;

class CleaningPayload extends BasePayload
{
    protected string $category = 'cleaning';
    protected array $requiredFields = ['area', 'bedrooms', 'bathrooms', 'rooms', 'deep_clean'];
}
