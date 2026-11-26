<?php

declare(strict_types=1);

namespace SchoolSystem\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

final class JwtTokens
{
    private string $secret;
    private string $issuer;
    private int $ttl;

    public function __construct()
    {
        $secret = getenv('JWT_SECRET') ?: '';
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must contain at least 32 characters.');
        }
        $this->secret = $secret;
        $this->issuer = getenv('JWT_ISSUER') ?: 'school-system-api';
        $this->ttl = max(60, (int) (getenv('JWT_TTL') ?: 900));
    }

    public function issue(int $userId, array $roles, array $permissions): string
    {
        $now = time();
        return JWT::encode([
            'iss' => $this->issuer,
            'sub' => (string) $userId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(16)),
            'roles' => array_values($roles),
            'permissions' => array_values($permissions),
        ], $this->secret, 'HS256');
    }

    public function decode(string $token): object
    {
        $claims = JWT::decode($token, new Key($this->secret, 'HS256'));
        if (($claims->iss ?? null) !== $this->issuer || !isset($claims->sub)) {
            throw new RuntimeException('Invalid token issuer or subject.');
        }
        return $claims;
    }

    public function ttl(): int { return $this->ttl; }
}
