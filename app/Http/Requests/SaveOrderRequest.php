<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Validation\Rule;

final class SaveOrderRequest extends ApiRequest
{
    public function rules(): array
    {
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'customer' => [$presence, 'required', 'array:name,email'],
            'customer.name' => ['required_with:customer', 'string', 'max:120'],
            'customer.email' => ['required_with:customer', 'string', 'email', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(OrderStatus::class)],
            'items' => [$presence, 'required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_name,quantity,price'],
            'items.*.product_name' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['required', 'numeric', 'regex:/\A\d{1,6}(?:\.\d{1,2})?\z/', 'gt:0'],
            'total' => ['prohibited'],
            'total_minor' => ['prohibited'],
            'user_id' => ['prohibited'],
            'currency' => ['prohibited'],
        ];
    }
}
