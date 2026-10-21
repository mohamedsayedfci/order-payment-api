<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Support\Str;

final class OrderTest extends ApiTestCase
{
    public function test_order_creation_calculates_exact_total_and_defaults_to_pending(): void
    {
        $payload = $this->orderPayload();
        unset($payload['status']);
        $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('data.total', '40.28')->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.items.0.line_total', '39.98');
        $this->assertDatabaseHas('orders', ['total_minor' => 4028, 'user_id' => $this->owner->id]);
    }

    public function test_orders_can_be_updated_and_items_replaced_atomically(): void
    {
        $id = $this->createOrder('pending');
        $this->patchJson("/api/v1/orders/{$id}", [
            'customer' => ['name' => 'Updated', 'email' => 'updated@example.com'],
            'status' => 'confirmed',
            'items' => [['product_name' => 'New item', 'quantity' => 3, 'price' => '1.25']],
        ])->assertOk()->assertJsonPath('data.total', '3.75')->assertJsonPath('data.customer.name', 'Updated')
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_status_only_update_preserves_customer_items_and_total(): void
    {
        $id = $this->createOrder('pending');
        $this->patchJson("/api/v1/orders/{$id}", ['status' => 'confirmed'])->assertOk()
            ->assertJsonPath('data.total', '40.28')->assertJsonCount(2, 'data.items');
    }

    public function test_lists_are_paginated_filtered_and_owner_scoped(): void
    {
        $this->createOrder('pending');
        $this->createOrder('confirmed');
        $other = User::factory()->create();
        $this->withToken(app(JwtService::class)->issue($other));
        $this->createOrder('pending');
        $this->withToken(app(JwtService::class)->issue($this->owner));
        $this->getJson('/api/v1/orders?status=pending&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/orders?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
    }

    public function test_owner_can_show_order_but_other_users_cannot_read_update_delete_or_pay(): void
    {
        $id = $this->createOrder();
        $this->getJson("/api/v1/orders/{$id}")->assertOk();
        $this->withToken(app(JwtService::class)->issue(User::factory()->create()));
        $this->getJson("/api/v1/orders/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/orders/{$id}", ['status' => 'cancelled'])->assertNotFound();
        $this->deleteJson("/api/v1/orders/{$id}")->assertNotFound();
        $this->getJson("/api/v1/orders/{$id}/payments")->assertNotFound();
        $this->pay($id, (string) Str::uuid())->assertNotFound();
    }

    public function test_unpaid_order_can_be_deleted_and_items_cascade(): void
    {
        $id = $this->createOrder();
        $this->deleteJson("/api/v1/orders/{$id}")->assertNoContent();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_order_cannot_be_deleted_even_after_failed_payment(): void
    {
        $id = $this->createOrder();
        $this->pay($id, (string) Str::uuid(), result: 'failed')->assertCreated();
        $this->deleteJson("/api/v1/orders/{$id}")->assertConflict()->assertJsonPath('error_code', 'order_has_payments');
    }

    public function test_order_details_are_immutable_after_any_payment_attempt(): void
    {
        $id = $this->createOrder();
        $this->pay($id, (string) Str::uuid(), result: 'failed')->assertCreated();
        $this->patchJson("/api/v1/orders/{$id}", ['items' => [['product_name' => 'Changed', 'quantity' => 1, 'price' => '1.00']]])
            ->assertConflict()->assertJsonPath('error_code', 'order_has_payments');
        $this->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.total', '40.28');
    }

    public function test_failed_order_can_be_cancelled_but_paid_order_cannot(): void
    {
        $failed = $this->createOrder();
        $this->pay($failed, (string) Str::uuid(), result: 'failed');
        $this->patchJson("/api/v1/orders/{$failed}", ['status' => 'cancelled'])->assertOk();
        $paid = $this->createOrder();
        $this->pay($paid, (string) Str::uuid());
        $this->patchJson("/api/v1/orders/{$paid}", ['status' => 'cancelled'])->assertConflict()->assertJsonPath('error_code', 'order_payment_active');
    }

    public function test_invalid_status_transitions_and_cancelled_creation_are_rejected(): void
    {
        $id = $this->createOrder();
        $this->patchJson("/api/v1/orders/{$id}", ['status' => 'pending'])->assertConflict();
        $this->patchJson("/api/v1/orders/{$id}", ['status' => 'cancelled'])->assertOk();
        $this->patchJson("/api/v1/orders/{$id}", ['status' => 'confirmed'])->assertConflict();
        $this->postJson('/api/v1/orders', $this->orderPayload('cancelled'))->assertConflict();
    }

    public function test_input_validation_rejects_malformed_prices_quantities_and_tampered_total(): void
    {
        foreach (['-1.00', '0', '1.234', '1e2', '1000000.00', 'abc'] as $price) {
            $payload = $this->orderPayload();
            $payload['items'][0]['price'] = $price;
            $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.price');
        }
        $payload = $this->orderPayload();
        $payload['items'][0]['quantity'] = 0;
        $payload['total'] = '0.01';
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity', 'total']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_empty_items_malformed_customer_and_invalid_filters_are_rejected(): void
    {
        $this->postJson('/api/v1/orders', ['customer' => ['name' => 'Only name'], 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer.email', 'items']);
        $this->getJson('/api/v1/orders?status=unknown&per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['status', 'per_page', 'page']);
        $this->getJson('/api/v1/orders/99999')->assertNotFound();
        $this->getJson('/api/v1/orders/'.str_repeat('9', 30))->assertNotFound();
    }
}
