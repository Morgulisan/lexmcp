<?php
declare(strict_types=1);

namespace MopolitiAuth;

use RuntimeException;

final class AppError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly bool $retryable = false,
        public readonly array $details = [],
        public readonly ?string $suggestedAction = null,
    ) {
        parent::__construct($message);
    }
}
