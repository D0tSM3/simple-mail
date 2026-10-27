<?php
// Run:  php tests/mapper_test.php      (no Gmail, database or network needed)
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/AppException.php';
require_once dirname(__DIR__) . '/src/ErrorMapper.php';

use GuzzleHttp\Exception\{ConnectException, RequestException, TransferException};
use GuzzleHttp\Psr7\{Request, Response};
use Google\Service\Exception as GoogleException;

$req = new Request('GET', 'https://gmail.googleapis.com/test');
$gerr = fn(int $code, string $reason) =>
    new GoogleException('upstream', $code, null, [['reason' => $reason]]);

$cases = [
    // [label, exception, expected status, expected code]
    ['Google 429',                       $gerr(429, 'rateLimitExceeded'),         429, 'GMAIL_RATE_LIMITED'],
    ['Google 403 userRateLimitExceeded', $gerr(403, 'userRateLimitExceeded'),     429, 'GMAIL_RATE_LIMITED'],
    ['Google 403 insufficient scope',    $gerr(403, 'insufficientPermissions'),   403, 'GMAIL_SCOPE_INSUFFICIENT'],
    ['Google 403 other',                 $gerr(403, 'forbidden'),                 403, 'GMAIL_ACCESS_DENIED'],
    ['Google 401',                       $gerr(401, 'authError'),                 401, 'RECONNECT_REQUIRED'],
    ['Google 404',                       $gerr(404, 'notFound'),                  404, 'GMAIL_NOT_FOUND'],
    ['Google 400',                       $gerr(400, 'invalidArgument'),           400, 'GMAIL_BAD_REQUEST'],
    ['Google 500 -> our 502',            $gerr(500, 'backendError'),              502, 'GMAIL_UPSTREAM_ERROR'],
    ['Google 503',                       $gerr(503, 'backendError'),              503, 'GMAIL_UNAVAILABLE'],
    ['Google 504',                       $gerr(504, 'timeout'),                   504, 'GMAIL_TIMEOUT'],
    ['Guzzle connect timeout (errno 28)',
        new ConnectException('cURL error 28: timed out', $req),                504, 'UPSTREAM_TIMEOUT'],
    ['Guzzle DNS/refused connection',
        new ConnectException('cURL error 6: Could not resolve host', $req),    502, 'BAD_GATEWAY'],
    ['Guzzle HTTP 503 response',
        RequestException::create($req, new Response(503)), 503, 'UPSTREAM_UNAVAILABLE'],
    ['Guzzle HTTP 500 response',
        RequestException::create($req, new Response(500)), 502, 'BAD_GATEWAY'],
    ['Guzzle HTTP 429 response',
        RequestException::create($req, new Response(429)), 429, 'UPSTREAM_RATE_LIMITED'],
    ['Guzzle other transfer error',      new TransferException('weird', $req), 502, 'BAD_GATEWAY'],
    ['AppException uses safe public message',
        new AppException(401, 'RECONNECT_REQUIRED', 'secret-token-value'),     401, 'RECONNECT_REQUIRED'],
    ['Unknown AppException code is generic',
        new AppException(418, 'UPSTREAM_SECRET', 'upstream body with token'),   500, 'INTERNAL_ERROR'],
    ['Plain bug -> 500',                 new LogicException('oops'),          500, 'INTERNAL_ERROR'],
];

$failed = 0;
foreach ($cases as [$label, $exception, $status, $code]) {
    $m = ErrorMapper::map($exception);
    $safe = !str_contains($m['message'], 'secret-token-value')
        && !str_contains($m['message'], 'upstream body with token');
    $ok = $m['status'] === $status && $m['code'] === $code && $safe;
    $failed += $ok ? 0 : 1;
    printf("%s  %-38s -> %d %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $m['status'], $m['code'],
        $ok ? '' : "   (expected $status $code)");
}
echo $failed ? "\n$failed FAILED\n" : "\nAll mapper tests passed.\n";
exit($failed ? 1 : 0);
