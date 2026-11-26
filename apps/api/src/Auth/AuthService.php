<?php

declare(strict_types=1);

namespace SchoolSystem\Auth;

use PDO;
use RuntimeException;

final class AuthService
{
    public function __construct(private readonly PDO $db, private readonly JwtTokens $jwt)
    {
    }

    public function login(string $email, string $password, string $ip, string $userAgent): array
    {
        $stmt = $this->db->prepare('SELECT id, email, password_hash, full_name, is_active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !(bool) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
            $this->audit(null, 'auth.login.failed', ['email' => mb_strtolower(trim($email))], $ip);
            throw new RuntimeException('Invalid email or password.');
        }

        [$roles, $permissions] = $this->accessFor((int) $user['id']);
        $accessToken = $this->jwt->issue((int) $user['id'], $roles, $permissions);
        $refreshToken = $this->newRefreshToken((int) $user['id'], $ip, $userAgent);
        $this->audit((int) $user['id'], 'auth.login.succeeded', [], $ip);

        return [
            'token_type' => 'Bearer',
            'access_token' => $accessToken,
            'expires_in' => $this->jwt->ttl(),
            'refresh_token' => $refreshToken,
            'user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'full_name' => $user['full_name'], 'roles' => $roles],
        ];
    }

    public function refresh(string $plainToken, string $ip, string $userAgent): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, user_id, expires_at, revoked_at FROM refresh_tokens WHERE token_hash = ? FOR UPDATE');
            $stmt->execute([hash('sha256', $plainToken)]);
            $row = $stmt->fetch();
            if (!$row || $row['revoked_at'] !== null || strtotime($row['expires_at']) <= time()) {
                throw new RuntimeException('Refresh token is invalid or expired.');
            }
            $this->db->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?')->execute([$row['id']]);
            $userStmt = $this->db->prepare('SELECT id, email, full_name, is_active FROM users WHERE id = ?');
            $userStmt->execute([$row['user_id']]);
            $user = $userStmt->fetch();
            if (!$user || !(bool) $user['is_active']) {
                throw new RuntimeException('Account is unavailable.');
            }
            [$roles, $permissions] = $this->accessFor((int) $user['id']);
            $accessToken = $this->jwt->issue((int) $user['id'], $roles, $permissions);
            $newRefresh = $this->newRefreshToken((int) $user['id'], $ip, $userAgent);
            $this->db->commit();
            return ['token_type' => 'Bearer', 'access_token' => $accessToken, 'expires_in' => $this->jwt->ttl(), 'refresh_token' => $newRefresh];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function logout(string $plainToken, int $userId, string $ip): void
    {
        $stmt = $this->db->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ? AND user_id = ? AND revoked_at IS NULL');
        $stmt->execute([hash('sha256', $plainToken), $userId]);
        $this->audit($userId, 'auth.logout', [], $ip);
    }

    public function accessFor(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT DISTINCT r.name AS role_name, p.name AS permission_name FROM user_roles ur JOIN roles r ON r.id = ur.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? AND r.is_active = 1');
        $stmt->execute([$userId]);
        $roles = $permissions = [];
        foreach ($stmt->fetchAll() as $row) {
            $roles[] = $row['role_name'];
            if ($row['permission_name'] !== null) $permissions[] = $row['permission_name'];
        }
        return [array_values(array_unique($roles)), array_values(array_unique($permissions))];
    }

    private function newRefreshToken(int $userId, string $ip, string $userAgent): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $ttl = max(3600, (int) (getenv('REFRESH_TOKEN_TTL') ?: 2592000));
        $stmt = $this->db->prepare('INSERT INTO refresh_tokens (user_id, token_hash, expires_at, created_ip, user_agent) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), ?, ?)');
        $stmt->execute([$userId, hash('sha256', $token), $ttl, $ip, substr($userAgent, 0, 500)]);
        return $token;
    }

    private function audit(?int $userId, string $event, array $metadata, string $ip): void
    {
        $stmt = $this->db->prepare('INSERT INTO audit_logs (user_id, event, metadata_json, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $event, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ip]);
    }
}
