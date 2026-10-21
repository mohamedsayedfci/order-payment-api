<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'order_id', 'method', 'status', 'amount_minor', 'currency',
        'idempotency_key', 'request_hash', 'gateway_reference', 'failure_code',
    ];

    protected $hidden = ['request_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'amount_minor' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
