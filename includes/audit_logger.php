<?php
/**
 * Digital Forensics Audit Trail Logger
 * Mencatat aktivitas pengguna secara immutable ke tabel audit_logs
 */
function logAuditTrail(PDO $pdo, ?int $userId, string $action, ?string $targetTable = null, ?int $targetId = null, ?string $details = null): void
{
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, target_table, target_id, details, ip_address, user_agent)
        VALUES (:user_id, :action, :target_table, :target_id, :details, :ip_address, :user_agent)
    ");

    $stmt->execute([
        ':user_id'      => $userId,
        ':action'       => $action,
        ':target_table' => $targetTable,
        ':target_id'    => $targetId,
        ':details'      => $details,
        ':ip_address'   => $ipAddress,
        ':user_agent'   => $userAgent
    ]);
}
