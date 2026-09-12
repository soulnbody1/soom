<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\RefreshTokenRepository;
use Illuminate\Support\Str;

class TokenService
{
    protected $tokenRepo;

    public function __construct(RefreshTokenRepository $tokenRepo)
    {
        $this->tokenRepo = $tokenRepo;
    }

    public function createTokens(User $user): array
    {
        $accessToken = $user->createToken('api-token')->plainTextToken;
        $rawRefreshToken = Str::random(64);
        $hashedRefreshToken = hash('sha256', $rawRefreshToken);

        $this->tokenRepo->updateOrInsertToken($user->id, $hashedRefreshToken);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $rawRefreshToken,
        ];
    }

    public function validateRefreshToken(string $token)
    {
        $hashedToken = hash('sha256', $token);

        return $this->tokenRepo->findValidToken($hashedToken);
    }

    public function consumeRefreshToken(string $token)
    {
        return $this->tokenRepo->consumeValidToken(hash('sha256', $token));
    }

    public function revokeRefreshToken(int $userId): void
    {
        $this->tokenRepo->deleteTokenByUserId($userId);
    }
}
