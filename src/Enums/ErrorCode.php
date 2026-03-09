<?php

declare(strict_types=1);

namespace Eco\Enums;

use Eco\Contracts\ErrorCodeContract;

/**
 * Built-in error codes provided by the library.
 *
 * For domain-specific codes, implement {@see ErrorCodeContract}
 * on your own enum and pass it directly to {@see Error}.
 */
enum ErrorCode: string implements ErrorCodeContract
{
    case GENERIC    = 'GENERIC_ERROR';
    case VALIDATION = 'VALIDATION_ERROR';

    public function value(): string
    {
        return $this->value;
    }
}