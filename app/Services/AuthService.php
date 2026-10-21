<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

final class AuthService
{
    private const UNKNOWN_USER_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function register(array $data): User
    {
        // The User model hashes passwords through its hashed cast.
        return User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
    }

    public function login(array $credentials): User
    {
        $user = User::query()->where('email', $credentials['email'])->first();

        // Check a hash even when the address is unknown, then use the same error.
        $passwordHash = $user?->password ?? self::UNKNOWN_USER_PASSWORD_HASH;
        $passwordMatches = Hash::check($credentials['password'], $passwordHash);

        if ($user === null || ! $passwordMatches) {
            throw new AuthenticationException('Invalid email or password.');
        }

        return $user;
    }
}
