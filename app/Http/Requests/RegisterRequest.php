<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\BcryptPassword;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:72', new BcryptPassword(), 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ];
    }
}
