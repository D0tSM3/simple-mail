<?php
declare(strict_types=1);

final class ApiResponse
{
    public static function success(array $data, int $status = 200): never
    {
        self::send($status, ['success' => true, 'data' => $data]);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        header('Cache-Control: no-store');
        exit;
    }

    public static function error(
        int $status, string $code, string $message,
        array $extra = [], array $headers = []
    ): never {
        foreach ($headers as $name => $value) header("$name: $value");

        self::send($status, ['success' => false, 'error' => array_merge([
            'code' => $code,
            'message' => $message,
            'requestId' => request_id(),   // same ID on every response
        ], $extra)]);
    }

    /** Map any exception, log the real details privately, answer generically. */
    public static function fromThrowable(Throwable $e): never
    {
        $m = ErrorMapper::map($e);

        log_event([
            'type' => 'error',
            'exception' => get_class($e),
            // Exception messages and upstream response bodies may contain credentials.
            'diagnostic' => 'Exception details intentionally omitted.',
            'upstreamStatus' => ErrorMapper::upstreamStatus($e),
            'mappedStatus' => $m['status'],
            'code' => $m['code'],
        ]);

        self::error(
            $m['status'], $m['code'], $m['message'],
            ['retryable' => $m['retryable']],
            $m['retryAfter'] ? ['Retry-After' => (string) $m['retryAfter']] : []
        );
    }

    private static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_THROW_ON_ERROR);
        exit;
    }
}
