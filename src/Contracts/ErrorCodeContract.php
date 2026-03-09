<?php

declare(strict_types=1);

namespace Eco\Contracts;

/**
 * Contract for error codes carried by {@see \Eco\Error}.
 *
 * Implement this interface on your own enum to define domain-specific
 * error codes beyond the built-in ones provided by {@see \Eco\ErrorCode}.
 *
 * ```php
 * enum AppErrorCode: string implements ErrorCodeContract
 * {
 *     case UNAUTHORIZED           = 'UNAUTHORIZED';
 *     case INSUFFICIENT_BALANCE   = 'INSUFFICIENT_BALANCE';
 *     case ORDER_ALREADY_CANCELLED = 'ORDER_ALREADY_CANCELLED';
 *
 *     public function value(): string
 *     {
 *         return $this->value;
 *     }
 * }
 * ```
 */
interface ErrorCodeContract
{
    /**
     * Returns the machine-readable string identifier for this error code.
     * Used for serialization, API responses, and programmatic comparisons.
     */
    public function value(): string;
}