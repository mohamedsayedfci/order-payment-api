<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\BcryptPassword;

final class LoginRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255'], 'password' => ['required', 'string', 'max:72', new BcryptPassword()]];
    }
}
