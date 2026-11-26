<?php

declare(strict_types=1);

namespace SchoolSystem\Schools;

use PDO;
use RuntimeException;

final class SchoolService
{
    private const SCHOOL_FIELDS = ['name_ar', 'name_en', 'code', 'email', 'phone', 'address', 'status'];
    private const BRANCH_FIELDS = ['name_ar', 'name_en', 'code', 'email', 'phone', 'address', 'status'];

    public function __construct(
        private readonly PDO $db,
        private readonly int $userId,
        private readonly array $roles,
        private readonly array $permissions,
    ) {
    }

    public function listSchools(int $limit, int $offset): array
    {
        $this->requirePermission('schools.read', 'schools.manage');
        if ($this->isSystemAdmin()) {
            $stmt = $this->db->prepare('SELECT id, name_ar, name_en, code, email, phone, address, status, created_at, updated_at FROM schools ORDER BY id DESC LIMIT ? OFFSET ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare('SELECT s.id, s.name_ar, s.name_en, s.code, s.email, s.phone, s.address, s.status, s.created_at, s.updated_at FROM schools s JOIN user_schools us ON us.school_id = s.id WHERE us.user_id = ? ORDER BY s.id DESC LIMIT ? OFFSET ?');
            $stmt->bindValue(1, $this->userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function createSchool(array $input, string $ip): array
    {
        $this->requirePermission('schools.manage');
        $data = $this->validated($input, self::SCHOOL_FIELDS, ['name_ar', 'code']);
        $this->validateStatus($data['status'] ?? 'active');
        $stmt = $this->db->prepare('INSERT INTO schools (name_ar, name_en, code, email, phone, address, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$data['name_ar'], $data['name_en'] ?? null, $data['code'], $data['email'] ?? null, $data['phone'] ?? null, $data['address'] ?? null, $data['status'] ?? 'active']);
        $id = (int) $this->db->lastInsertId();
        $this->audit('schools.created', ['school_id' => $id, 'code' => $data['code']], $ip);
        return $this->getSchool($id);
    }

    public function getSchool(int $schoolId): array
    {
        $this->requirePermission('schools.read', 'schools.manage');
        $this->assertSchoolAccess($schoolId);
        $stmt = $this->db->prepare('SELECT id, name_ar, name_en, code, email, phone, address, status, created_at, updated_at FROM schools WHERE id = ?');
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();
        if (!$school) throw new RuntimeException('School not found.');
        return $school;
    }

    public function updateSchool(int $schoolId, array $input, string $ip): array
    {
        $this->requirePermission('schools.manage');
        $this->assertSchoolAccess($schoolId);
        $data = $this->validated($input, self::SCHOOL_FIELDS, []);
        if ($data === []) throw new RuntimeException('At least one school field is required.');
        if (isset($data['status'])) $this->validateStatus($data['status']);
        $this->updateRecord('schools', $schoolId, $data);
        $this->audit('schools.updated', ['school_id' => $schoolId, 'fields' => array_keys($data)], $ip);
        return $this->getSchool($schoolId);
    }

    public function archiveSchool(int $schoolId, string $ip): void
    {
        $this->requirePermission('schools.manage');
        $this->assertSchoolAccess($schoolId);
        $stmt = $this->db->prepare("UPDATE schools SET status = 'archived' WHERE id = ? AND status <> 'archived'");
        $stmt->execute([$schoolId]);
        if ($stmt->rowCount() === 0) $this->getSchool($schoolId);
        $this->audit('schools.archived', ['school_id' => $schoolId], $ip);
    }

    public function listBranches(int $schoolId, int $limit, int $offset): array
    {
        $this->requirePermission('schools.read', 'schools.manage');
        $this->assertSchoolAccess($schoolId);
        $stmt = $this->db->prepare('SELECT id, school_id, name_ar, name_en, code, email, phone, address, status, created_at, updated_at FROM school_branches WHERE school_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $schoolId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function createBranch(int $schoolId, array $input, string $ip): array
    {
        $this->requirePermission('branches.manage', 'schools.manage');
        $this->assertSchoolAccess($schoolId);
        $data = $this->validated($input, self::BRANCH_FIELDS, ['name_ar', 'code']);
        $this->validateStatus($data['status'] ?? 'active');
        $stmt = $this->db->prepare('INSERT INTO school_branches (school_id, name_ar, name_en, code, email, phone, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$schoolId, $data['name_ar'], $data['name_en'] ?? null, $data['code'], $data['email'] ?? null, $data['phone'] ?? null, $data['address'] ?? null, $data['status'] ?? 'active']);
        $id = (int) $this->db->lastInsertId();
        $this->audit('school_branches.created', ['school_id' => $schoolId, 'branch_id' => $id, 'code' => $data['code']], $ip);
        return $this->getBranch($schoolId, $id);
    }

    public function getBranch(int $schoolId, int $branchId): array
    {
        $this->requirePermission('schools.read', 'schools.manage', 'branches.manage');
        $this->assertSchoolAccess($schoolId);
        $stmt = $this->db->prepare('SELECT id, school_id, name_ar, name_en, code, email, phone, address, status, created_at, updated_at FROM school_branches WHERE school_id = ? AND id = ?');
        $stmt->execute([$schoolId, $branchId]);
        $branch = $stmt->fetch();
        if (!$branch) throw new RuntimeException('Branch not found.');
        return $branch;
    }

    public function updateBranch(int $schoolId, int $branchId, array $input, string $ip): array
    {
        $this->requirePermission('branches.manage', 'schools.manage');
        $this->assertSchoolAccess($schoolId);
        $this->getBranch($schoolId, $branchId);
        $data = $this->validated($input, self::BRANCH_FIELDS, []);
        if ($data === []) throw new RuntimeException('At least one branch field is required.');
        if (isset($data['status'])) $this->validateStatus($data['status']);
        $this->updateRecord('school_branches', $branchId, $data);
        $this->audit('school_branches.updated', ['school_id' => $schoolId, 'branch_id' => $branchId, 'fields' => array_keys($data)], $ip);
        return $this->getBranch($schoolId, $branchId);
    }

    public function archiveBranch(int $schoolId, int $branchId, string $ip): void
    {
        $this->requirePermission('branches.manage', 'schools.manage');
        $this->assertSchoolAccess($schoolId);
        $this->getBranch($schoolId, $branchId);
        $stmt = $this->db->prepare("UPDATE school_branches SET status = 'archived' WHERE school_id = ? AND id = ? AND status <> 'archived'");
        $stmt->execute([$schoolId, $branchId]);
        $this->audit('school_branches.archived', ['school_id' => $schoolId, 'branch_id' => $branchId], $ip);
    }

    private function validated(array $input, array $allowed, array $required): array
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) throw new RuntimeException('Unknown fields: ' . implode(', ', $unknown));
        $data = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if ($value !== null && !is_string($value)) throw new RuntimeException("Field {$field} must be a string or null.");
            if (is_string($value)) $value = trim($value);
            if (in_array($field, $required, true) && ($value === null || $value === '')) throw new RuntimeException("Field {$field} is required.");
            if ($field === 'email' && $value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid email is required.');
            $max = match ($field) { 'name_ar', 'name_en' => 190, 'code' => 50, 'email' => 190, 'phone' => 40, 'address' => 500, 'status' => 20 };
            if (is_string($value) && strlen($value) > $max) throw new RuntimeException("Field {$field} exceeds {$max} characters.");
            $data[$field] = $value === '' ? null : $value;
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) throw new RuntimeException("Field {$field} is required.");
        }
        return $data;
    }

    private function updateRecord(string $table, int $id, array $data): void
    {
        $sets = [];
        foreach (array_keys($data) as $field) $sets[] = "{$field} = ?";
        $stmt = $this->db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute([...array_values($data), $id]);
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, ['active', 'inactive', 'archived'], true)) throw new RuntimeException('Status must be active, inactive, or archived.');
    }

    private function requirePermission(string ...$accepted): void
    {
        if ($this->isSystemAdmin()) return;
        foreach ($accepted as $permission) if (in_array($permission, $this->permissions, true)) return;
        throw new RuntimeException('Forbidden: required permission is missing.');
    }

    private function assertSchoolAccess(int $schoolId): void
    {
        if ($this->isSystemAdmin()) return;
        $stmt = $this->db->prepare('SELECT 1 FROM user_schools WHERE user_id = ? AND school_id = ?');
        $stmt->execute([$this->userId, $schoolId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('School not found or access denied.');
    }

    private function isSystemAdmin(): bool { return in_array('system_admin', $this->roles, true); }

    private function audit(string $event, array $metadata, string $ip): void
    {
        $stmt = $this->db->prepare('INSERT INTO audit_logs (user_id, event, metadata_json, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$this->userId, $event, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $ip]);
    }
}
