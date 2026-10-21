<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Payments\GatewayResult;
use App\Payments\Gateways\CreditCardGateway;
use App\Payments\PaymentContext;
use App\Payments\PaymentGateway;
use App\Services\JwtService;
use Illuminate\Support\Str;
use RuntimeException;

final class PaymentTest extends ApiTestCase
{
    public function test_both_strategies_can_process_full_order_payment(): void
    {
        foreach (['credit_card' => 'sim_cc_', 'paypal' => 'sim_paypal_'] as $method => $prefix) {
            $id = $this->createOrder();
            $response = $this->pay($id, (string) Str::uuid(), $method)->assertCreated()
                ->assertJsonPath('data.status', 'successful')->assertJsonPath('data.method', $method)
                ->assertJsonPath('data.amount', '40.28')->assertHeader('Idempotent-Replayed', 'false');
            $this->assertStringStartsWith($prefix, $response->json('data.gateway_reference'));
        }
    }

    public function test_pending_and_cancelled_orders_cannot_be_paid(): void
    {
        $pending = $this->createOrder('pending');
        $this->pay($pending, (string) Str::uuid())->assertConflict()->assertJsonPath('error_code', 'order_not_confirmed');
        $id = $this->createOrder();
        $this->patchJson("/api/v1/orders/{$id}", ['status' => 'cancelled'])->assertOk();
        $this->pay($id, (string) Str::uuid())->assertConflict();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_failed_payment_is_persisted_and_can_be_retried_with_a_new_key(): void
    {
        $id = $this->createOrder();
        $this->pay($id, (string) Str::uuid(), result: 'failed')->assertCreated()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'card_declined');
        $this->pay($id, (string) Str::uuid(), 'paypal')->assertCreated()->assertJsonPath('data.status', 'successful');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_identical_request_is_replayed_without_another_charge(): void
    {
        $id = $this->createOrder();
        $key = (string) Str::uuid();
        $first = $this->pay($id, $key)->assertCreated()->json('data.id');
        $this->pay($id, strtoupper($key))->assertOk()->assertJsonPath('data.id', $first)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_failed_result_replays_and_omitted_default_result_is_equivalent(): void
    {
        $id = $this->createOrder();
        $key = (string) Str::uuid();
        $this->postJson("/api/v1/orders/{$id}/payments", ['method' => 'credit_card'], ['Idempotency-Key' => $key])->assertCreated();
        $this->pay($id, $key)->assertOk();
        $failed = $this->createOrder();
        $failedKey = (string) Str::uuid();
        $paymentId = $this->pay($failed, $failedKey, result: 'failed')->assertCreated()->json('data.id');
        $this->pay($failed, $failedKey, result: 'failed')->assertOk()->assertJsonPath('data.id', $paymentId)->assertJsonPath('data.status', 'failed');
    }

    public function test_reusing_key_with_different_method_order_or_result_is_a_conflict(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();
        $key = (string) Str::uuid();
        $this->pay($first, $key, result: 'failed')->assertCreated();
        $this->pay($first, $key, 'paypal', 'failed')->assertConflict()->assertJsonPath('error_code', 'idempotency_key_reused');
        $this->pay($second, $key, result: 'failed')->assertConflict();
        $this->pay($first, $key, result: 'successful')->assertConflict();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_new_key_cannot_charge_an_already_paid_order(): void
    {
        $id = $this->createOrder();
        $this->pay($id, (string) Str::uuid())->assertCreated();
        $this->pay($id, (string) Str::uuid())->assertConflict()->assertJsonPath('error_code', 'order_already_paid_or_processing');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_pending_payment_blocks_another_charge_and_cancellation(): void
    {
        $id = $this->createOrder();
        $this->pay($id, (string) Str::uuid())->assertCreated();
        Payment::query()->firstOrFail()->update(['status' => PaymentStatus::Pending]);
        $this->pay($id, (string) Str::uuid())->assertConflict();
        $this->patchJson("/api/v1/orders/{$id}", ['status' => 'cancelled'])->assertConflict();
    }

    public function test_payment_validation_requires_header_and_enabled_known_method(): void
    {
        $id = $this->createOrder();
        $this->postJson("/api/v1/orders/{$id}/payments", ['method' => 'unknown', 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['method', 'idempotency_key']);
        $this->pay($id, 'invalid')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        config(['payments.gateways.paypal.enabled' => false]);
        $this->pay($id, (string) Str::uuid(), 'paypal')->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->postJson("/api/v1/orders/{$id}/payments", ['method' => 'credit_card', 'amount' => 1, 'card_number' => '4111111111111111'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'card_number']);
    }

    public function test_payment_details_lists_filters_and_owner_isolation(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();
        $payment = $this->pay($first, (string) Str::uuid())->assertCreated()->json('data.id');
        $this->pay($second, (string) Str::uuid(), 'paypal', 'failed')->assertCreated();
        $this->getJson("/api/v1/payments/{$payment}")->assertOk()->assertJsonPath('data.order_id', $first)->assertJsonMissingPath('data.idempotency_key');
        $this->getJson('/api/v1/payments?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/payments?status=failed')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/orders/{$first}/payments")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/payments?per_page=0&status=bad')->assertUnprocessable();
        $this->withToken(app(JwtService::class)->issue(User::factory()->create()));
        $this->getJson("/api/v1/payments/{$payment}")->assertNotFound();
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_idempotency_keys_are_scoped_per_user(): void
    {
        $key = (string) Str::uuid();
        $this->pay($this->createOrder(), $key)->assertCreated();
        $this->withToken(app(JwtService::class)->issue(User::factory()->create()));
        $this->pay($this->createOrder(), $key)->assertCreated();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_gateway_exception_rolls_back_payment_without_misreporting_a_decline(): void
    {
        $id = $this->createOrder();
        $this->app->bind(CreditCardGateway::class, fn () => new class () implements PaymentGateway {
            public function charge(PaymentContext $context): GatewayResult
            {
                throw new RuntimeException('Simulated infrastructure failure');
            }
        });
        $this->pay($id, (string) Str::uuid())->assertStatus(500);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_new_gateway_requires_only_an_implementation_and_configuration(): void
    {
        config(['payments.gateways.bank_transfer' => ['enabled' => true, 'driver' => TestBankGateway::class]]);
        $this->pay($this->createOrder(), (string) Str::uuid(), 'bank_transfer')->assertCreated()
            ->assertJsonPath('data.method', 'bank_transfer')->assertJsonPath('data.status', 'successful');
    }
}

final class TestBankGateway implements PaymentGateway
{
    public function charge(PaymentContext $context): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Successful, 'test_bank_'.$context->paymentId);
    }
}
