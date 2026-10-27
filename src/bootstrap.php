<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use Google\Client;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/AppException.php';
require_once __DIR__ . '/ErrorMapper.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Categorizer.php';
require_once __DIR__ . '/TokenStore.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

const GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.metadata';

// Never print PHP errors into responses (they would corrupt JSON). Log them.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function env(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? $default;
}

/** One ID per request, reused by every response and every log line. */
function request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(8));
}
header('X-Request-Id: ' . request_id());

function log_event(array $fields): void
{
    error_log(json_encode(['requestId' => request_id()] + $fields));
}

/** Plain-text error for browser-navigation routes (OAuth callback). */
function plain_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message . "\nRequest ID: " . request_id());
}

// Any uncaught exception under /api/ becomes a clean JSON 500/502/etc.
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
    set_exception_handler(fn(Throwable $e) => ApiResponse::fromThrowable($e));
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure'   => $isHttps,
    'samesite' => 'Lax',
    'path'     => '/',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function debug_enabled(): bool
{
    return env('APP_ENV') === 'local' && env('DEBUG_ERRORS') === 'true'
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'), env('DB_NAME', 'smart_mailbox')),
        env('DB_USER'), env('DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function googleClient(): Client
{
    $client = new Client();
    $client->setClientId((string) env('GOOGLE_CLIENT_ID'));
    $client->setClientSecret((string) env('GOOGLE_CLIENT_SECRET'));
    $client->setRedirectUri(env('APP_URL', 'http://localhost:8080') . '/auth/callback.php');
    $client->setScopes(['openid', 'email', GMAIL_SCOPE]);
    $client->setAccessType('offline');
    $client->setPrompt('consent');            // makes Google return a refresh token
    $client->setIncludeGrantedScopes(true);
    // Fail fast instead of hanging: connect 5s, total 15s.
    $client->setHttpClient(new GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5]));
    return $client;
}
