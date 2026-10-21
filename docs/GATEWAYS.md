# Adding a payment strategy

## 1. Implement the contract

For another **local simulator**, create `app/Payments/Gateways/BankTransferGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Payments\GatewayResult;
use App\Payments\PaymentContext;
use App\Payments\PaymentGateway;

final class BankTransferGateway implements PaymentGateway
{
    public function charge(PaymentContext $context): GatewayResult
    {
        $failed = $context->simulationResult === 'failed';

        return new GatewayResult(
            $failed ? PaymentStatus::Failed : PaymentStatus::Successful,
            'sim_bank_'.$context->paymentId,
            $failed ? 'bank_declined' : null,
        );
    }
}
```

`PaymentContext` provides the immutable server-side payment ID, order ID, amount, currency, and simulator outcome. `GatewayResult` holds the final status, reference, and optional safe failure code. Pending results are rejected by this synchronous contract.

## 2. Register configuration

Add one entry to `gateways` in `config/payments.php`:

```php
'bank_transfer' => [
    'driver' => App\Payments\Gateways\BankTransferGateway::class,
    'enabled' => (bool) env('BANK_TRANSFER_ENABLED', true),
    'api_key' => env('BANK_TRANSFER_API_KEY'),
],
```

Keep method names at most 50 characters and use safe lowercase identifiers. Configuration drivers must implement the interface. Gateways can receive dependencies through constructor injection; a configured HTTP client can be bound in a service provider for real integrations. Never call `env()` from runtime business code, and never expose keys in API resources or logs.

After changing environment or configuration:

```bash
php artisan config:clear
```

No FormRequest, enum, controller or service edit is needed. Payment methods intentionally use configuration keys instead of a closed PHP enum. Historical methods remain strings even if their driver is disabled later.

## 3. Exercise it

Use a fresh confirmed order and a fresh UUID header:

```http
POST /api/v1/orders/1/payments
Authorization: Bearer <JWT>
Idempotency-Key: 123e4567-e89b-42d3-a456-426614174000
Content-Type: application/json

{"method":"bank_transfer","simulation_result":"successful"}
```

See `PaymentTest::test_new_gateway_requires_only_an_implementation_and_configuration` for a runtime extension test. Add strategy-specific unit cases for declines and references as needed. If adapting to a real provider, first replace the orchestration boundary described in [ARCHITECTURE.md](ARCHITECTURE.md); a network request inside the existing simulator transaction is not a complete real-payment integration.
