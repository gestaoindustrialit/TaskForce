<?php
declare(strict_types=1);

final class RateLimiter
{
    public static function tooManyLoginAttempts(PDO $pdo, string $identifier, string $ipAddress, int $maxAttempts = 5, int $windowSeconds = 900): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND ip_address = ? AND attempted_at >= datetime(\'now\', ? ) AND was_success = 0');
            $stmt->execute([$identifier, $ipAddress, '-' . $windowSeconds . ' seconds']);

            return (int) $stmt->fetchColumn() >= $maxAttempts;
        } catch (Throwable $exception) {
            error_log('[TaskForce] RateLimiter read failed: ' . $exception->getMessage());
            return false;
        }
    }

    public static function recordLoginAttempt(PDO $pdo, string $identifier, string $ipAddress, bool $success): void
    {
        try {
            $stmt = $pdo->prepare('INSERT INTO login_attempts(identifier, ip_address, was_success) VALUES (?, ?, ?)');
            $stmt->execute([$identifier, $ipAddress, $success ? 1 : 0]);
        } catch (Throwable $exception) {
            error_log('[TaskForce] RateLimiter write failed: ' . $exception->getMessage());
        }
    }
}
