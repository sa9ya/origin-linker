<?php

declare(strict_types=1);

namespace Origin\Linker\Payload;

class TransferPayload extends BasePayload
{
    protected string $category = 'transfer';
    protected array $requiredFields = ['pickup', 'dropoff', 'trip_type'];
}
