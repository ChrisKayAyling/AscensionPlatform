<?php

namespace Ascension\Exceptions;

/**
 * Base exception for all Ascension framework failures.
 *
 * Consolidates the constructor/stdOutput pair that used to be duplicated,
 * with an unqualified `Throwable` type-hint, across every exception subclass.
 */
class AscensionException extends \Exception
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function stdOutput(int $code, string $message): void
    {
        echo "Ascension Exception Raised: " . $code . " - " . $message . "\n";
    }
}
