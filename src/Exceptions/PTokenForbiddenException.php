<?php

declare(strict_types=1);

namespace Wenbo\PToken\Exceptions;

/**
 * Thrown when a token lacks the required abilities (scopes).
 * Maps to HTTP 403 Forbidden.
 */
class PTokenForbiddenException extends PTokenException
{
    protected readonly int $statusCode;

    public function __construct(string $message = 'Forbidden', int $code = 0, int $statusCode = 403)
    {
        parent::__construct($message, $code);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
