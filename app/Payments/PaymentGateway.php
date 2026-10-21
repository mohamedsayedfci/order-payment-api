<?php

declare(strict_types=1);

namespace App\Payments;

interface PaymentGateway
{
    public function charge(PaymentContext $context): GatewayResult;
}
