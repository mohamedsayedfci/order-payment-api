<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Payments\GatewayRegistry;
use Illuminate\Validation\Rule;

final class ProcessPaymentRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        // Header is authoritative; a JSON field cannot substitute for the header.
        $key = $this->header('Idempotency-Key');
        $this->merge(['idempotency_key' => is_string($key) ? strtolower($key) : $key]);
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(app(GatewayRegistry::class)->methods())],
            'idempotency_key' => ['required', 'uuid'],
            'simulation_result' => ['sometimes', 'required', Rule::in(['successful', 'failed'])],
            'amount' => ['prohibited'],
            'amount_minor' => ['prohibited'],
            'card_number' => ['prohibited'],
            'cvv' => ['prohibited'],
        ];
    }
}
