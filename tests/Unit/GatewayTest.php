<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use App\Payments\GatewayResult;
use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PaypalGateway;
use App\Payments\PaymentContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GatewayTest extends TestCase
{
    public function test_gateway_results_and_references_are_deterministic(): void
    {
        foreach ([new CreditCardGateway(), new PaypalGateway()] as $gateway) {
            foreach (['successful', 'failed'] as $outcome) {
                $context = new PaymentContext('payment-id', 1, 100, 'USD', $outcome);
                $first = $gateway->charge($context);
                self::assertSame(PaymentStatus::from($outcome), $first->status);
                self::assertSame($first->reference, $gateway->charge($context)->reference);
                self::assertSame($outcome === 'failed', $first->failureCode !== null);
            }
        }
    }

    public function test_synchronous_result_cannot_remain_pending(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GatewayResult(PaymentStatus::Pending, 'invalid');
    }
}
