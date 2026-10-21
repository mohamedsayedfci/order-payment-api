<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use LogicException;
use stdClass;
use UnexpectedValueException;

final class JwtService
{
    public function issue(User $user): string
    {
        $now = time();

        return JWT::encode([
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'sub' => (string) $user->getKey(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttlSeconds(),
            'jti' => (string) Str::uuid(),
        ], $this->secret(), 'HS256');
    }

    public function authenticate(?string $token): ?User
    {
        if ($token === null || strlen($token) > 4096) {
            return null;
        }

        try {
            // A fixed Key algorithm prevents accepting a client-selected algorithm.
            $claims = JWT::decode($token, new Key($this->secret(), 'HS256'));
        } catch (UnexpectedValueException) {
            return null;
        }

        if (! $this->hasRequiredClaims($claims)) {
            return null;
        }

        return User::query()->find($claims->sub);
    }

    public function ttlSeconds(): int
    {
        return max(1, (int) config('jwt.ttl')) * 60;
    }

    private function hasRequiredClaims(stdClass $claims): bool
    {
        $issuerMatches = ($claims->iss ?? null) === config('jwt.issuer');
        $audienceMatches = ($claims->aud ?? null) === config('jwt.audience');

        if (! $issuerMatches || ! $audienceMatches) {
            return false;
        }

        if (! is_string($claims->sub ?? null) || ! ctype_digit($claims->sub)) {
            return false;
        }

        $hasTimestamps = is_int($claims->iat ?? null)
            && is_int($claims->nbf ?? null)
            && is_int($claims->exp ?? null);

        if (! $hasTimestamps || $claims->exp <= $claims->iat) {
            return false;
        }

        return is_string($claims->jti ?? null) && Str::isUuid($claims->jti);
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');
        if (strlen($secret) < 32) {
            throw new LogicException('JWT_SECRET must contain at least 32 random characters. Run php artisan jwt:secret.');
        }

        return $secret;
    }
}
