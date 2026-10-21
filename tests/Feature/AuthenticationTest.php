<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_returns_a_usable_jwt(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Mohamed', 'email' => 'Mohamed@Example.com',
            'password' => 'SecurePass123', 'password_confirmation' => 'SecurePass123',
        ])->assertCreated()->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'mohamed@example.com')->assertJsonMissingPath('data.user.password');

        $this->assertTrue(Hash::check('SecurePass123', User::firstOrFail()->password));
        $this->withToken($response->json('data.access_token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.name', 'Mohamed');
    }

    public function test_registration_rejects_duplicate_email_and_invalid_password(): void
    {
        User::factory()->create(['email' => 'mohamed@example.com']);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mohamed', 'email' => 'MOHAMED@example.com', 'password' => 'weak', 'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_accepts_normalized_email_and_returns_token(): void
    {
        User::factory()->create(['email' => 'mohamed@example.com', 'password' => 'SecurePass123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'MOHAMED@example.com', 'password' => 'SecurePass123'])
            ->assertOk()->assertJsonStructure(['data' => ['access_token', 'expires_in', 'user']]);
    }

    public function test_login_uses_generic_error_for_wrong_password_and_unknown_email(): void
    {
        User::factory()->create(['email' => 'mohamed@example.com']);
        foreach (['mohamed@example.com', 'unknown@example.com'] as $email) {
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'incorrect'])
                ->assertUnauthorized()->assertJsonPath('message', 'Invalid email or password.');
        }
    }

    public function test_protected_routes_return_json_401_without_accept_header(): void
    {
        $this->get('/api/v1/orders')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->postJson('/api/v1/orders', [])->assertUnauthorized();
        $this->getJson('/api/v1/payments')->assertUnauthorized();
    }

    public function test_expired_tampered_wrong_issuer_audience_and_algorithm_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $claims = [
            'iss' => config('jwt.issuer'), 'aud' => config('jwt.audience'), 'sub' => (string) $user->id,
            'iat' => time() - 60, 'nbf' => time() - 60, 'exp' => time() + 600, 'jti' => (string) Str::uuid(),
        ];
        $secret = config('jwt.secret');
        $tokens = [
            JWT::encode(array_replace($claims, ['exp' => time() - 1]), $secret, 'HS256'),
            JWT::encode(array_replace($claims, ['iss' => 'attacker']), $secret, 'HS256'),
            JWT::encode(array_replace($claims, ['aud' => 'wrong-client']), $secret, 'HS256'),
            JWT::encode(array_diff_key($claims, ['exp' => true]), $secret, 'HS256'),
            JWT::encode(array_replace($claims, ['nbf' => time() + 300]), $secret, 'HS256'),
            JWT::encode($claims, str_repeat('different-secret', 4), 'HS256'),
            JWT::encode($claims, str_repeat('long-key', 16), 'HS512'),
            'not.a.valid.jwt',
        ];
        foreach ($tokens as $token) {
            $this->withToken($token)->getJson('/api/v1/orders')->assertUnauthorized();
        }
    }

    public function test_token_for_deleted_user_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = app(JwtService::class)->issue($user);
        $user->delete();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_validation_prevents_bcrypt_truncation_and_null_bytes(): void
    {
        foreach (['SecurePass123'.str_repeat('é', 40), "SecurePass123\0"] as $password) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Mohamed', 'email' => 'password@example.com',
                'password' => $password, 'password_confirmation' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $password = 'SecurePass123'.str_repeat('a', 59);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mohamed', 'email' => 'password@example.com',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated();
        $this->postJson('/api/v1/auth/login', ['email' => 'password@example.com', 'password' => $password.'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_malformed_email_is_validated_without_breaking_the_rate_limiter(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => ['invalid'], 'password' => 'SecurePass123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'limited@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'limited@example.com', 'password' => 'wrong'])->assertStatus(429);
    }
}
