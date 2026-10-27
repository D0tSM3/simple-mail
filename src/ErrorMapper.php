<?php
declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Google\Service\Exception as GoogleServiceException;

/**
 * Translates "what went wrong upstream" into "what WE tell the browser".
 * Pure function of the exception: no globals, so tests can call it directly.
 */
final class ErrorMapper
{
    public static function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof AppException =>
                self::app($e),
            $e instanceof GoogleServiceException => self::google($e),
            $e instanceof ConnectException       => self::transport($e),
            $e instanceof RequestException       => self::httpResponse($e),
            $e instanceof TransferException      => self::transport($e),
            default => self::r(500, 'INTERNAL_ERROR',
                'An unexpected server error occurred.'),
        };
    }

    /** Allow only application-owned public messages, even for AppException. */
    private static function app(AppException $e): array
    {
        $safe = [
            'GMAIL_NOT_CONNECTED' => [401, 'Gmail is not connected.'],
            'RECONNECT_REQUIRED' => [401, 'Gmail authorization needs to be reconnected.'],
            'TOKEN_REFRESH_FAILED' => [502, 'Could not refresh Gmail authorization.'],
            'TOKEN_STORAGE_UNAVAILABLE' => [500, 'Secure token storage is unavailable.'],
            'OAUTH_NOT_CONFIGURED' => [503, 'Google sign-in is not configured.'],
        ];
        [$status, $message] = $safe[$e->errorCode] ?? [500, 'An unexpected server error occurred.'];
        $retryable = $e->errorCode === 'TOKEN_REFRESH_FAILED' && $e->retryable;
        return self::r($status, isset($safe[$e->errorCode]) ? $e->errorCode : 'INTERNAL_ERROR', $message, $retryable);
    }

    /** Best-effort upstream status for logging. */
    public static function upstreamStatus(Throwable $e): ?int
    {
        if ($e instanceof GoogleServiceException) return (int) $e->getCode();
        if ($e instanceof RequestException && $e->getResponse()) {
            return $e->getResponse()->getStatusCode();
        }
        return null;
    }

    private static function google(GoogleServiceException $e): array
    {
        $up = (int) $e->getCode();
        $reasons = [];
        foreach ($e->getErrors() ?? [] as $err) {
            if (isset($err['reason'])) $reasons[] = $err['reason'];
        }
        $has = fn(string ...$r) => array_intersect($r, $reasons) !== [];

        // Google reports some rate limits as HTTP 403, so read the reason too.
        return match (true) {
            $up === 429 || $has('userRateLimitExceeded', 'rateLimitExceeded') =>
                self::r(429, 'GMAIL_RATE_LIMITED',
                    'Gmail is receiving too many requests. Retry shortly.', true, 5),
            $up === 401 =>
                self::r(401, 'RECONNECT_REQUIRED',
                    'Gmail rejected our authorization. Please reconnect.'),
            $up === 403 && $has('insufficientPermissions', 'insufficientAuthenticationScopes') =>
                self::r(403, 'GMAIL_SCOPE_INSUFFICIENT',
                    'This operation needs additional Gmail permissions.'),
            $up === 403 =>
                self::r(403, 'GMAIL_ACCESS_DENIED', 'Gmail denied this request.'),
            $up === 404 =>
                self::r(404, 'GMAIL_NOT_FOUND', 'The Gmail resource was not found.'),
            $up === 400 =>
                self::r(400, 'GMAIL_BAD_REQUEST', 'Gmail rejected the request parameters.'),
            $up === 503 =>
                self::r(503, 'GMAIL_UNAVAILABLE', 'Gmail is temporarily unavailable.', true, 5),
            $up === 504 =>
                self::r(504, 'GMAIL_TIMEOUT', 'Gmail took too long to respond.', true),
            $up >= 500 && $up <= 599 =>
                self::r(502, 'GMAIL_UPSTREAM_ERROR', 'Gmail returned a server error.', true),
            default =>
                self::r(502, 'GMAIL_REQUEST_FAILED', 'The Gmail request could not be completed.'),
        };
    }

    /** Guzzle error that carries an HTTP response (e.g. token endpoint, 5xx). */
    private static function httpResponse(RequestException $e): array
    {
        $resp = $e->getResponse();
        if ($resp === null) return self::transport($e);

        return match (true) {
            $resp->getStatusCode() === 429 =>
                self::r(429, 'UPSTREAM_RATE_LIMITED', 'Upstream rate limit hit.', true, 5),
            $resp->getStatusCode() === 503 =>
                self::r(503, 'UPSTREAM_UNAVAILABLE', 'Upstream service unavailable.', true, 5),
            $resp->getStatusCode() === 504 =>
                self::r(504, 'UPSTREAM_TIMEOUT', 'Upstream service timed out.', true),
            $resp->getStatusCode() >= 500 =>
                self::r(502, 'BAD_GATEWAY', 'Upstream returned a server error.', true),
            default =>
                self::r(502, 'UPSTREAM_REJECTED', 'Upstream rejected our request.'),
        };
    }

    /** No HTTP response at all: DNS failure, refused connection, timeout. */
    private static function transport(TransferException $e): array
    {
        return self::isTimeout($e)
            ? self::r(504, 'UPSTREAM_TIMEOUT', 'Upstream service timed out.', true)
            : self::r(502, 'BAD_GATEWAY', 'Could not reach the upstream service.', true);
    }

    private static function isTimeout(TransferException $e): bool
    {
        return stripos($e->getMessage(), 'timed out') !== false
            || stripos($e->getMessage(), 'timeout') !== false;
    }

    private static function r(
        int $status, string $code, string $message,
        bool $retryable = false, ?int $retryAfter = null
    ): array {
        return compact('status', 'code', 'message', 'retryable', 'retryAfter');
    }
}
