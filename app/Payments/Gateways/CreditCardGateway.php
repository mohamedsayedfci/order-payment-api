<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Payments\GatewayResult;
use App\Payments\PaymentContext;
use App\Payments\PaymentGateway;

final class CreditCardGateway implements PaymentGateway
{
    public function charge(PaymentContext $context): GatewayResult
    {
        // This is an explicit simulator. No card data or provider calls are involved.
        $failed = $context->simulationResult === 'failed';

        return new GatewayResult(
            $failed ? PaymentStatus::Failed : PaymentStatus::Successful,
            'sim_cc_'.$context->paymentId,
            $failed ? 'card_declined' : null,
        );
    }
}
