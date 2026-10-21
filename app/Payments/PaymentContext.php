<?php

declare(strict_types=1);

namespace App\Payments;

final readonly class PaymentContext
{
    public function __construct(
        public string $paymentId,
        public int $orderId,
        public int $amountMinor,
        public string $currency,
        public string $simulationResult,
    ) {
    }
}
