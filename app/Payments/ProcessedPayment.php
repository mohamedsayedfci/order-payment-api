<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Payment;

final readonly class ProcessedPayment
{
    public function __construct(public Payment $payment, public bool $replayed = false)
    {
    }
}
