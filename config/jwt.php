<?php

declare(strict_types=1);

return [
    'secret' => env('JWT_SECRET'),
    'issuer' => env('JWT_ISSUER', 'order-payment-api'),
    'audience' => env('JWT_AUDIENCE', 'order-payment-client'),
    'ttl' => (int) env('JWT_TTL', 60), // Minutes.
];
