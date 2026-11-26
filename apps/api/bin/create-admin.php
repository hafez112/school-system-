<?php

declare(strict_types=1);

use SchoolSystem\Infrastructure\Database;

require_once __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run in CLI.\n");
    exit(1);
}
$email = filter_var($argv[1] ?? '', FILTER_VALIDATE_EMAIL);
$name = trim($argv[2] ?? 'System Administrator');
$password = $argv[3] ?? '';
if (!$email || strlen($password) < 12) {
    fwrite(STDERR, "Usage: php bin/create-admin.php email name 'password-at-least-12-chars'\n");
    exit(1);
}
$db = Database::connect();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO users (email, password_hash, full_name) VALUES (?, ?, ?)');
    $stmt->execute([strtolower($email), password_hash($password, PASSWORD_DEFAULT), $name]);
    $userId = (int) $db->lastInsertId();
    $role = $db->query("SELECT id FROM roles WHERE name = 'system_admin'")->fetchColumn();
    if (!$role) throw new RuntimeException('system_admin role is missing; run database initialization first.');
    $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $role]);
    $db->commit();
    fwrite(STDOUT, "Administrator created: {$email}\n");
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Failed: {$e->getMessage()}\n");
    exit(1);
}
