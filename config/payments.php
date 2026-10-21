<?php

declare(strict_types=1);

use App\Payments\Gateways\CreditCardGateway;
use App\Payments\Gateways\PaypalGateway;

return [
    'currency' => 'USD',
    // These drivers deliberately simulate results and never contact a provider.
    'gateways' => [
        'credit_card' => [
            'driver' => CreditCardGateway::class,
            'enabled' => (bool) env('CREDIT_CARD_ENABLED', true),
            'api_key' => env('CREDIT_CARD_API_KEY'),
        ],
        'paypal' => [
            'driver' => PaypalGateway::class,
            'enabled' => (bool) env('PAYPAL_ENABLED', true),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'secret' => env('PAYPAL_SECRET'),
        ],
    ],
];
