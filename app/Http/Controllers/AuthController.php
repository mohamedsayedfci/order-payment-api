<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly JwtService $jwtService,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->login($request->validated());

        return $this->tokenResponse($user);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function tokenResponse(User $user, int $statusCode = 200): JsonResponse
    {
        $responseData = [
            'access_token' => $this->jwtService->issue($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtService->ttlSeconds(),
            'user' => new UserResource($user),
        ];

        return response()->json(['data' => $responseData], $statusCode)
            ->header('Cache-Control', 'no-store');
    }
}
