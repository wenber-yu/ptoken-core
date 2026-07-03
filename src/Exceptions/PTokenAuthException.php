<?php

declare(strict_types=1);

namespace Wenbo\PToken\Exceptions;

/**
 * Base authentication exception for PToken core library.
 */
class PTokenAuthException extends PTokenException
{
    protected readonly int $statusCode;

    public function __construct(string $message = 'Authentication failed', int $code = 0, int $statusCode = 401)
    {
        parent::__construct($message, $code);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
