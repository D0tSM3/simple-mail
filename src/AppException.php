<?php
declare(strict_types=1);

/** An error we raise ourselves, already carrying the HTTP status to return. */
final class AppException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }
}
