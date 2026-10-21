<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->withToken(app(JwtService::class)->issue($this->owner));
    }

    protected function orderPayload(string $status = 'confirmed'): array
    {
        return [
            'customer' => ['name' => 'Mohamed Sayed', 'email' => 'customer@example.com'],
            'status' => $status,
            'items' => [
                ['product_name' => 'Keyboard', 'quantity' => 2, 'price' => '19.99'],
                ['product_name' => 'Cable', 'quantity' => 3, 'price' => '0.10'],
            ],
        ];
    }

    protected function createOrder(string $status = 'confirmed'): int
    {
        return $this->postJson('/api/v1/orders', $this->orderPayload($status))->assertCreated()->json('data.id');
    }

    protected function pay(int $order, string $key, string $method = 'credit_card', string $result = 'successful')
    {
        return $this->postJson("/api/v1/orders/{$order}/payments", [
            'method' => $method, 'simulation_result' => $result,
        ], ['Idempotency-Key' => $key]);
    }
}
