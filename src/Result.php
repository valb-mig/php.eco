<?php

declare(strict_types=1);

namespace Eco;

/**
 * Represents the result of an operation that may fail without throwing an exception.
 *
 * Use this for validations and business rules where failure is an expected,
 * recoverable outcome — not an exceptional one.
 *
 * Prefer {@see Result::fail()} over throwing exceptions when:
 *  - The user may have provided invalid input
 *  - A business rule was not satisfied
 *  - A resource was not found based on user-provided data
 *
 * Prefer throwing exceptions when:
 *  - A database or external service is unavailable
 *  - An impossible/invariant state is reached (likely a bug)
 *
 * @template T The type of the successful value
 */
final class Result
{
    private bool $success;

    /** @var T */
    private mixed $value;

    /** @var Error[] */
    private array $errors;

    private function __construct(bool $success, mixed $value = null, array $errors = [])
    {
        $this->success = $success;
        $this->value   = $value;
        $this->errors  = $errors;
    }

    /**
     * Creates a successful Result carrying the given value.
     *
     * Use when the operation produces a meaningful return value.
     *
     * @param  T    $value
     * @return self<T>
     */
    public static function ok(mixed $value): self
    {
        return new self(true, $value);
    }

    /**
     * Creates a successful Result with no value.
     *
     * Use for operations that succeed without producing a value
     * (e.g. delete, update, send email).
     *
     * ```php
     * function deleteUser(int $id): Result {
     *     $this->repo->delete($id);
     *     return Result::void();
     * }
     * ```
     *
     * @return self<null>
     */
    public static function void(): self
    {
        return new self(true, null);
    }

    /**
     * Creates a failed Result carrying one or more errors.
     * Plain strings are automatically wrapped in {@see Error::generic()}.
     *
     * @param  string|Error ...$errors
     * @return self<never>
     */
    public static function fail(string|Error ...$errors): self
    {
        $normalized = array_map(
            fn($e) => $e instanceof Error ? $e : Error::generic($e),
            $errors
        );

        return new self(false, null, $normalized);
    }

    /** Returns true when the operation succeeded. */
    public function isOk(): bool
    {
        return $this->success;
    }

    /** Returns true when the operation failed. */
    public function isFail(): bool
    {
        return !$this->success;
    }

    /**
     * Returns the successful value.
     *
     * @throws \LogicException If called on a failed Result.
     * @return T
     */
    public function getValue(): mixed
    {
        if ($this->isFail()) {
            throw new \LogicException('Cannot get value from a failed Result.');
        }

        return $this->value;
    }

    /**
     * Returns all errors carried by this Result.
     *
     * @return Error[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns every error message as a plain string array.
     *
     * @return string[]
     */
    public function getErrorMessages(): array
    {
        return array_map(fn(Error $e) => $e->message, $this->errors);
    }

    /**
     * Transforms the successful value using the given callback.
     * If this Result is a failure, it is returned unchanged.
     *
     * @param  callable(T): mixed $fn
     * @return self
     */
    public function map(callable $fn): self
    {
        if ($this->isFail()) {
            return $this;
        }

        return self::ok($fn($this->value));
    }

    /**
     * Chains an operation that itself returns a Result.
     * Short-circuits on the first failure — subsequent steps are skipped.
     *
     * Use {@see Result::combine()} instead when steps are independent
     * and you want to collect all errors at once.
     *
     * @param  callable(T): Result $fn
     * @return self
     */
    public function flatMap(callable $fn): self
    {
        if ($this->isFail()) {
            return $this;
        }

        return $fn($this->value);
    }

    /**
     * Executes a side-effect on the successful value without altering it.
     * If this Result is a failure, the callback is skipped.
     *
     * Useful for logging, caching, or triggering events mid-pipeline:
     * ```php
     * return parseInput($data)
     *     ->tap(fn($v) => $cache->store($v))
     *     ->flatMap(fn($v) => persist($v));
     * ```
     *
     * @param  callable(T): void $fn
     * @return self<T>
     */
    public function tap(callable $fn): self
    {
        if ($this->isOk()) {
            $fn($this->value);
        }

        return $this;
    }

    /**
     * Executes a side-effect callback when this Result is a failure.
     * The Result itself is returned unchanged, making it safe to use inline.
     *
     * Useful for logging without interrupting the pipeline:
     * ```php
     * return validate($data)
     *     ->onFail(fn($errors) => $logger->warning('Validation failed', $errors))
     *     ->flatMap(fn($data) => process($data));
     * ```
     *
     * @param  callable(Error[]): void $fn
     * @return self
     */
    public function onFail(callable $fn): self
    {
        if ($this->isFail()) {
            $fn($this->errors);
        }

        return $this;
    }

    /**
     * Attempts to recover from a failure by running the given callback.
     * If this Result is successful, it is returned unchanged.
     *
     * The callback receives the current errors and must return a new Result —
     * either a recovered success or a (possibly different) failure.
     *
     * ```php
     * return fetchFromCache($key)
     *     ->recover(fn($errors) => fetchFromDatabase($key))
     *     ->recover(fn($errors) => Result::ok($defaultValue));
     * ```
     *
     * @param  callable(Error[]): Result<T> $fn
     * @return self<T>
     */
    public function recover(callable $fn): self
    {
        if ($this->isOk()) {
            return $this;
        }

        return $fn($this->errors);
    }

    /**
     * Returns the value directly, or throws if the Result is a failure.
     *
     * Use only when you are certain the Result is successful (e.g. right after
     * a {@see Result::combine()} check). Calling this on a failure is a
     * programming error and will throw a {@see \LogicException}.
     *
     * @throws \LogicException
     * @return T
     */
    public function unwrap(): mixed
    {
        if ($this->isFail()) {
            $messages = implode(', ', $this->getErrorMessages());
            throw new \LogicException("Unwrap failed: {$messages}");
        }

        return $this->value;
    }

    /**
     * Returns the value if successful; otherwise calls the given callback
     * with the errors and returns null.
     *
     * The callback is responsible for deciding what happens on failure
     * (respond with HTTP 422, log, throw, exit, etc.).
     *
     * Always call exit/throw inside the callback if you do not want
     * execution to continue with a null value.
     *
     * ```php
     * $input = CreateUserInput::create($data)
     *     ->unwrapOrHandle(function (array $errors): void {
     *         http_response_code(422);
     *         echo json_encode(['errors' => $errors]);
     *         exit;
     *     });
     * ```
     *
     * @param  callable(Error[]): void $onFail
     * @return T|null
     */
    public function unwrapOrHandle(callable $onFail): mixed
    {
        if ($this->isFail()) {
            $onFail($this->errors);
            return null;
        }

        return $this->value;
    }

    /**
     * Returns the value if successful; otherwise returns the given default.
     *
     * Use when failure has an acceptable fallback and no handling is needed.
     *
     * ```php
     * $displayName = parseName($raw)->unwrapOr('Anonymous');
     * ```
     *
     * @param  T $default
     * @return T
     */
    public function unwrapOr(mixed $default): mixed
    {
        return $this->isOk() ? $this->value : $default;
    }

    /**
     * Returns a ready-made callback for {@see unwrapOrHandle()} that
     * throws a RuntimeException with all error messages joined.
     *
     * Useful when you want to convert a failed Result into an exception
     * (e.g. inside a context that already has a global exception handler).
     *
     * @return callable(Error[]): never
     */
    public static function throwOnFail(): callable
    {
        return function (array $errors): void {
            $messages = implode(', ', array_map(fn($e) => $e->message, $errors));
            throw new \RuntimeException($messages);
        };
    }

    /**
     * Runs all given Results and collects every error from each failure.
     * Returns ok carrying $value only when all Results succeed.
     *
     * Unlike {@see flatMap()}, which short-circuits on the first failure,
     * combine() always evaluates every Result — making it ideal for form
     * validation where you want to show all errors at once.
     *
     * Pass the value you want to carry forward as the first argument.
     * On failure, the value is discarded and all errors are returned.
     *
     * ```php
     * return Result::ok($data)
     *     ->flatMap(fn($data) => Result::combine($data,
     *         !empty($data['name'])  ? Result::void() : Result::fail(Error::validation('name',  'Required.')),
     *         !empty($data['email']) ? Result::void() : Result::fail(Error::validation('email', 'Required.')),
     *     ))
     *     ->map(fn($data) => new User($data['name'], $data['email']));
     * ```
     *
     * When no value needs to be carried, pass null explicitly:
     * ```php
     * Result::combine(null, $resultA, $resultB);
     * ```
     *
     * @param  mixed   $value     The value to carry on success.
     * @param  Result  ...$results
     * @return self
     */
    public static function combine(mixed $value, Result ...$results): self
    {
        $allErrors = [];

        foreach ($results as $result) {
            if ($result->isFail()) {
                $allErrors = array_merge($allErrors, $result->getErrors());
            }
        }

        return empty($allErrors) ? self::ok($value) : new self(false, null, $allErrors);
    }
}