<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $user->orders()->with('items')->orderByDesc('id');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page)->appends($filters);
    }

    public function find(User $user, int $orderId): Order
    {
        return $user->orders()->with('items')->findOrFail($orderId);
    }

    public function create(User $user, array $data): Order
    {
        $status = OrderStatus::from($data['status'] ?? OrderStatus::Pending->value);

        if ($status === OrderStatus::Cancelled) {
            throw new BusinessRuleViolation('invalid_order_status', 'A new order must be pending or confirmed.');
        }

        return DB::transaction(function () use ($user, $data, $status): Order {
            $order = $user->orders()->create([
                'customer_name' => $data['customer']['name'],
                'customer_email' => $data['customer']['email'],
                'status' => $status,
                'currency' => config('payments.currency'),
                'total_minor' => 0,
            ]);

            $this->replaceItems($order, $data['items']);

            return $order->load('items');
        });
    }

    public function update(User $user, int $orderId, array $data): Order
    {
        return DB::transaction(function () use ($user, $orderId, $data): Order {
            $order = $user->orders()->lockForUpdate()->findOrFail($orderId);
            $this->ensureOrderCanBeUpdated($order, $data);

            if (isset($data['status'])) {
                $this->changeStatus($order, OrderStatus::from($data['status']));
            }

            if (isset($data['customer'])) {
                $order->customer_name = $data['customer']['name'];
                $order->customer_email = $data['customer']['email'];
            }

            if (isset($data['items'])) {
                $this->replaceItems($order, $data['items']);
            }

            $order->save();

            return $order->load('items');
        });
    }

    public function delete(User $user, int $orderId): void
    {
        DB::transaction(function () use ($user, $orderId): void {
            // Payment processing locks this row too, so delete cannot race a charge.
            $order = $user->orders()->lockForUpdate()->findOrFail($orderId);

            if ($order->payments()->exists()) {
                throw new BusinessRuleViolation('order_has_payments', 'An order with payment attempts cannot be deleted.');
            }

            $order->delete();
        });
    }

    private function ensureOrderCanBeUpdated(Order $order, array $data): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw new BusinessRuleViolation('order_cancelled', 'A cancelled order cannot be modified.');
        }

        $changesCustomerOrItems = isset($data['customer']) || isset($data['items']);

        if ($changesCustomerOrItems && $order->payments()->exists()) {
            throw new BusinessRuleViolation('order_has_payments', 'Customer and items cannot change after a payment attempt.');
        }
    }

    private function changeStatus(Order $order, OrderStatus $newStatus): void
    {
        $returnsToPending = $order->status === OrderStatus::Confirmed && $newStatus === OrderStatus::Pending;

        if ($returnsToPending) {
            throw new BusinessRuleViolation('invalid_status_transition', 'A confirmed order cannot return to pending.');
        }

        if ($newStatus === OrderStatus::Cancelled) {
            $hasActivePayment = $order->payments()
                ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Successful])
                ->exists();

            if ($hasActivePayment) {
                throw new BusinessRuleViolation('order_payment_active', 'An order with a pending or successful payment cannot be cancelled.');
            }
        }

        $order->status = $newStatus;
    }

    private function replaceItems(Order $order, array $items): void
    {
        $itemRows = [];
        $orderTotalMinor = 0;

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            $unitPriceMinor = Money::toMinor((string) $item['price']);
            $lineTotalMinor = $unitPriceMinor * $quantity;

            $itemRows[] = [
                'product_name' => $item['product_name'],
                'quantity' => $quantity,
                'unit_price_minor' => $unitPriceMinor,
                'line_total_minor' => $lineTotalMinor,
            ];

            $orderTotalMinor += $lineTotalMinor;
        }

        $order->items()->delete();
        $order->items()->createMany($itemRows);
        $order->total_minor = $orderTotalMinor;
        $order->save();
    }
}
