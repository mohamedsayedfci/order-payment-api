<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\PaymentStatus;
use InvalidArgumentException;

final readonly class GatewayResult
{
    public function __construct(
        public PaymentStatus $status,
        public string $reference,
        public ?string $failureCode = null,
    ) {
        if ($status === PaymentStatus::Pending) {
            throw new InvalidArgumentException('Synchronous gateways must return a final result.');
        }
    }
}
