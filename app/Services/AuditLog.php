<?php
declare(strict_types=1);

final class AuditLog
{
    public static function write(PDO $pdo, ?int $userId, string $action, array $details = []): void
    {
        $json = $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $pdo->prepare('INSERT INTO audit_logs(user_id, action, details_json) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $action, $json]);
    }
}
