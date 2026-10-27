<?php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

// Invisible (404) unless explicitly enabled AND called from this machine.
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (env('APP_ENV') !== 'local' || !debug_enabled() || !$local) {
    ApiResponse::error(404, 'NOT_FOUND', 'Resource not found.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error(405, 'METHOD_NOT_ALLOWED', 'Use GET.', [], ['Allow' => 'GET']);
}

$raw = $_GET['code'] ?? '';
if (!is_string($raw) || !preg_match('/^\d{3}$/', $raw)) {
    ApiResponse::error(400, 'INVALID_STATUS', 'Provide a valid three-digit status.');
}
$code = (int) $raw;

$allowed = [200, 201, 204, 400, 401, 403, 404, 405, 409, 422, 429, 500, 502, 503, 504];
if (!in_array($code, $allowed, true)) {
    ApiResponse::error(422, 'UNSUPPORTED_TEST_STATUS', 'This status is not enabled in the error lab.');
}

// Optional: ?fail=2 -> fail twice, then succeed (lets you watch retries work).
if (isset($_GET['fail'])) {
    if (!is_string($_GET['fail']) || !preg_match('/^[0-3]$/', $_GET['fail'])) {
        ApiResponse::error(400, 'INVALID_FAILURE_COUNT', 'Failure count must be between 0 and 3.');
    }
    $key = 'lab_calls';
    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
    if ($_SESSION[$key] > (int) $_GET['fail']) {
        $_SESSION[$key] = 0;
        ApiResponse::success(['message' => 'Succeeded after simulated failures.']);
    }
}

match ($code) {
    200 => ApiResponse::success(['message' => 'Test request succeeded.']),
    201 => ApiResponse::success(['message' => 'Test resource created.'], 201),
    204 => ApiResponse::noContent(),
    default => ApiResponse::error(
        $code, 'TEST_HTTP_' . $code,
        'Deliberately generated test error.',
        ['retryable' => $code === 429 || $code >= 500],
        $code === 429 ? ['Retry-After' => '3'] : []
    ),
};
