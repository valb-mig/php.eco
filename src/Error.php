<?php

declare(strict_types=1);

namespace Eco;

use Eco\Contracts\ErrorCodeContract;
use Eco\Enums\ErrorCode;

/**
 * Represents an error carried by a failed {@see Result}.
 *
 * Immutable by design — every property is read-only and set only once
 * at construction time. Use the semantic factory methods instead of
 * calling the constructor directly.
 *
 * The $code property accepts any {@see ErrorCodeContract} implementation,
 * so you can bring your own domain-specific enum:
 *
 * ```php
 * enum AppErrorCode: string implements ErrorCodeContract
 * {
 *     case UNAUTHORIZED = 'UNAUTHORIZED';
 *     case INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';
 *
 *     public function value(): string { return $this->value; }
 * }
 *
 * // Then use it directly:
 * Error::make(AppErrorCode::UNAUTHORIZED, 'Access denied.')
 * Error::make(AppErrorCode::INSUFFICIENT_BALANCE, 'Not enough credits.', 'balance')
 * ```
 */
final class Error
{
    /**
     * @param ErrorCodeContract $code    Machine-readable identifier.
     *                                   Use {@see ErrorCode} for built-in codes or
     *                                   your own enum implementing {@see ErrorCodeContract}.
     * @param string            $message Human-readable description of what went wrong.
     * @param string            $field   The input field that caused the error, if applicable.
     *                                   Useful for mapping validation errors back to form fields.
     */
    public function __construct(
        public readonly ErrorCodeContract $code,
        public readonly string            $message,
        public readonly string            $field = '',
    ) {}

    /**
     * Creates an error with any code implementing {@see ErrorCodeContract}.
     *
     * This is the universal factory — use it for domain-specific codes
     * that go beyond the built-in {@see ErrorCode} cases.
     *
     * ```php
     * Error::make(AppErrorCode::UNAUTHORIZED,        'Access denied.')
     * Error::make(AppErrorCode::INSUFFICIENT_BALANCE, 'Not enough credits.', 'balance')
     * ```
     */
    public static function make(ErrorCodeContract $code, string $message, string $field = ''): self
    {
        return new self($code, $message, $field);
    }

    /**
     * Creates a generic, unclassified error.
     * Prefer {@see make()} with a specific code when the error type is known.
     */
    public static function generic(string $message): self
    {
        return new self(ErrorCode::GENERIC, $message);
    }

    /**
     * Creates a validation error tied to a specific input field.
     *
     * Use when user-provided data does not meet format or constraint rules
     * (e.g. empty required field, invalid e-mail, value out of range).
     *
     * ```php
     * Error::validation('email', 'Must be a valid e-mail address.')
     * Error::validation('age',   'Must be at least 18.')
     * ```
     */
    public static function validation(string $field, string $message): self
    {
        return new self(ErrorCode::VALIDATION, $message, $field);
    }

    /**
     * Returns a structured array representation of this error.
     *
     * Empty values are filtered out so the output stays clean for JSON responses.
     *
     * ```php
     * ['code' => 'VALIDATION_ERROR', 'message' => '...', 'field' => 'email']
     * ```
     */
    public function toArray(): array
    {
        return array_filter([
            'code'    => $this->code->value(),
            'message' => $this->message,
            'field'   => $this->field ?: null,
        ]);
    }

    /**
     * Returns a human-readable string representation.
     * Prefixes the message with the field name when present.
     *
     * Example output:
     *  - `[email] Must be a valid e-mail address. (VALIDATION_ERROR)`
     *  - `Access denied. (UNAUTHORIZED)`
     */
    public function __toString(): string
    {
        $prefix = $this->field ? "[{$this->field}] " : '';

        return "{$prefix}{$this->message} ({$this->code->value()})";
    }
}