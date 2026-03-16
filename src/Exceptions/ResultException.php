<?php

declare(strict_types=1);

namespace Eco\Exceptions;

use Eco\Error;

/**
 * Thrown when a {@see Result} is unwrapped in a failed state.
 *
 * Extends RuntimeException so callers can catch either this specific
 * class or the broader RuntimeException — whichever fits their context.
 */
final class ResultException extends \RuntimeException
{
    /** @var Error[] */
    private array $errors;

    /**
     * @param Error[] $errors
     */
    public function __construct(array $errors, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->errors = $errors;

        if ($message === '') {
            $message = implode(', ', array_map(fn(Error $e) => $e->message, $errors));
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Returns the errors that caused this exception.
     *
     * @return Error[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}