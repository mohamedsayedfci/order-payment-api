<?php

declare(strict_types=1);

namespace App\Payments;

use Illuminate\Contracts\Container\Container;
use LogicException;

final readonly class GatewayRegistry
{
    public function __construct(private Container $container)
    {
    }

    public function methods(): array
    {
        $enabledMethods = [];

        foreach (config('payments.gateways') as $method => $settings) {
            if ($settings['enabled'] ?? false) {
                $enabledMethods[] = $method;
            }
        }

        return $enabledMethods;
    }

    public function resolve(string $method): PaymentGateway
    {
        if (! in_array($method, $this->methods(), true)) {
            throw new LogicException('Unsupported or disabled payment gateway.');
        }

        $gatewaySettings = config('payments.gateways')[$method];
        $gateway = $this->container->make($gatewaySettings['driver']);

        if (! $gateway instanceof PaymentGateway) {
            throw new LogicException('Payment gateway must implement PaymentGateway.');
        }

        return $gateway;
    }
}
