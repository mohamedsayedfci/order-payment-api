<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\GatewayRegistry;
use App\Payments\GatewayResult;
use App\Payments\PaymentContext;
use App\Payments\ProcessedPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class PaymentService
{
    public function __construct(private GatewayRegistry $gateways)
    {
    }

    public function paginate(User $user, array $filters, ?int $orderId = null): LengthAwarePaginator
    {
        if ($orderId !== null) {
            $user->orders()->findOrFail($orderId);
        }

        $query = $user->payments()->orderByDesc('created_at')->orderByDesc('id');

        if ($orderId !== null) {
            $query->where('order_id', $orderId);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page)->appends($filters);
    }

    public function find(User $user, string $paymentId): Payment
    {
        return $user->payments()->findOrFail($paymentId);
    }

    public function process(User $user, int $orderId, array $data): ProcessedPayment
    {
        return DB::transaction(function () use ($user, $orderId, $data): ProcessedPayment {
            $order = $this->lockOrderForPayment($user, $orderId);
            $method = $data['method'];
            $simulationResult = $data['simulation_result'] ?? 'successful';
            $requestHash = $this->requestFingerprint($order, $method, $simulationResult);

            $existingPayment = $this->findExistingPayment($user, $data['idempotency_key'], $requestHash);

            if ($existingPayment !== null) {
                return new ProcessedPayment($existingPayment, replayed: true);
            }

            $this->ensureOrderCanBePaid($order);
            $payment = $this->createPendingPayment($user, $order, $data, $requestHash);
            $gatewayResult = $this->chargePayment($payment, $simulationResult);

            $payment->update([
                'status' => $gatewayResult->status,
                'gateway_reference' => $gatewayResult->reference,
                'failure_code' => $gatewayResult->failureCode,
            ]);

            return new ProcessedPayment($payment);
        }, attempts: 3);
    }

    private function lockOrderForPayment(User $user, int $orderId): Order
    {
        // Always lock the owner first, then the order. This also serializes keys
        // reused across different orders belonging to the same owner.
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

        return $user->orders()->lockForUpdate()->findOrFail($orderId);
    }

    private function requestFingerprint(Order $order, string $method, string $simulationResult): string
    {
        $requestValues = [$order->id, $method, $simulationResult];
        $encodedRequest = json_encode($requestValues, JSON_THROW_ON_ERROR);

        return hash('sha256', $encodedRequest);
    }

    private function findExistingPayment(User $user, string $key, string $requestHash): ?Payment
    {
        $payment = $user->payments()->where('idempotency_key', $key)->first();

        if ($payment === null) {
            return null;
        }

        if (! hash_equals($payment->request_hash, $requestHash)) {
            throw new BusinessRuleViolation('idempotency_key_reused', 'This Idempotency-Key was used for a different payment request.');
        }

        return $payment;
    }

    private function ensureOrderCanBePaid(Order $order): void
    {
        if ($order->status !== OrderStatus::Confirmed) {
            throw new BusinessRuleViolation('order_not_confirmed', 'Payments can only be processed for confirmed orders.');
        }

        $hasActivePayment = $order->payments()
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Successful])
            ->exists();

        if ($hasActivePayment) {
            throw new BusinessRuleViolation('order_already_paid_or_processing', 'This order already has a successful or pending payment.');
        }
    }

    private function createPendingPayment(User $user, Order $order, array $data, string $requestHash): Payment
    {
        return $user->payments()->create([
            'order_id' => $order->id,
            'method' => $data['method'],
            'status' => PaymentStatus::Pending,
            'amount_minor' => $order->total_minor,
            'currency' => $order->currency,
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $requestHash,
        ]);
    }

    private function chargePayment(Payment $payment, string $simulationResult): GatewayResult
    {
        $gateway = $this->gateways->resolve($payment->method);
        $context = new PaymentContext(
            paymentId: $payment->id,
            orderId: $payment->order_id,
            amountMinor: $payment->amount_minor,
            currency: $payment->currency,
            simulationResult: $simulationResult,
        );

        // Local simulators are safe inside this transaction. Real provider calls
        // need the outbox and reconciliation flow described in ARCHITECTURE.md.
        return $gateway->charge($context);
    }
}
