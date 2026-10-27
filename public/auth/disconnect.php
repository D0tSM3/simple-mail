<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error(405, 'METHOD_NOT_ALLOWED', 'Use POST.', [], ['Allow' => 'POST']);
}
if (empty($_SESSION['user_id'])) {
    ApiResponse::error(401, 'UNAUTHENTICATED', 'Please connect your Gmail account.');
}
$sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
    ApiResponse::error(403, 'CSRF_INVALID', 'Invalid or missing CSRF token.');
}

$userId = (int) $_SESSION['user_id'];

try {
    // Best effort: tell Google to revoke, but always delete our copy.
    try {
        if ($token = TokenStore::revocableToken($userId)) {
            googleClient()->revokeToken($token);
        }
    } catch (Throwable $e) {
        log_event(['type' => 'revoke_failed', 'exception' => get_class($e),
                   'diagnostic' => 'Revocation failure details intentionally omitted.']);
    }

    db()->prepare('DELETE FROM app_users WHERE id = ?')->execute([$userId]); // cascades
    $_SESSION = [];
    session_destroy();
    ApiResponse::noContent();
} catch (Throwable $e) {
    ApiResponse::fromThrowable($e);
}
