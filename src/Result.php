<?php

declare(strict_types=1);

namespace Eco;

use Eco\Exceptions\ResultException;

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
 * ----------------------------------------------------------------------------
 * Pipeline overview
 * ----------------------------------------------------------------------------
 *
 *  Method          Runs when   Alters Result?   Purpose
 *  --------------- ----------- ---------------- ------------------------------
 *  then()          ok          no               Side-effect with the value
 *  orThen()        fail        no               Side-effect with the errors
 *  transform()     ok          yes — new value  Transform the carried value
 *  flatMap()       ok          yes — new Result Chain a Result-returning op
 *  otherwise()     fail        yes — new Result Recover from failure
 *
 * ----------------------------------------------------------------------------
 * Unwrap overview
 * ----------------------------------------------------------------------------
 *
 *  Method                  Returns on ok   Returns on fail
 *  ----------------------- --------------- ------------------------------------
 *  unwrap()                value           throws ResultException
 *  default($default)       value           $default
 *  unwrapOrHandle($fn)     value           calls $fn(errors), returns null
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
        /** @var Error[] $errors */
        $this->errors  = $errors;
    }

    /**
     * Creates a successful Result carrying the given value.
     *
     * When called with no arguments, creates a successful Result with no value.
     * Use this for operations that succeed without producing a meaningful return,
     * such as deletes, updates, or fire-and-forget actions.
     *
     * ```php
     * return Result::ok($user);
     * return Result::ok(['id' => 1, 'name' => 'Ana']);
     *
     * // No value:
     * function deleteUser(int $id): Result
     * {
     *     $this->repo->delete($id);
     *     return Result::ok();
     * }
     * ```
     *
     * @param  T $value
     * @return self<T>
     */
    public static function ok(mixed $value = null): self
    {
        return new self(true, $value);
    }

    /**
     * Creates a failed Result carrying one or more errors.
     *
     * Plain strings are automatically wrapped in {@see Error::generic()}.
     * Pass typed {@see Error} instances for richer, machine-readable errors.
     *
     * ```php
     * Result::fail('Something went wrong.');
     * Result::fail(Error::validation('email', 'Invalid format.'));
     * Result::fail(
     *     Error::validation('name',  'Required.'),
     *     Error::validation('email', 'Invalid format.'),
     * );
     * ```
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

        /** @var self<never> $result */
        $result = new self(false, null, $normalized);
        return $result;
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
     * Returns all errors carried by this Result.
     * Returns an empty array when the Result is successful.
     *
     * @return Error[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns every error message as a plain string array.
     * Useful for quick serialization or display.
     *
     * ```php
     * $result->getErrorMessages(); // ['Required.', 'Invalid format.']
     * ```
     *
     * @return string[]
     */
    public function getErrorMessages(): array
    {
        return array_map(fn(Error $e) => $e->message, $this->errors);
    }

    /**
     * Executes a side-effect with the successful value, without altering it.
     * Skipped entirely when the Result is a failure.
     *
     * Use for logging, caching, or triggering events mid-pipeline
     * when you do not want to change the carried value:
     *
     * ```php
     * getUserById($id)
     *     ->then(fn($user) => $logger->info("User loaded: {$user->name}"))
     *     ->then(fn($user) => $cache->store("user:{$id}", $user))
     *     ->transform(fn($user) => $user->toArray());
     * ```
     *
     * @param  callable(T): void $fn
     * @return self<T>
     */
    public function then(callable $fn): self
    {
        if ($this->isOk()) {
            $fn($this->value);
        }

        return $this;
    }

    /**
     * Transforms the successful value using the given callback.
     * Returns a new Result carrying the transformed value.
     * Skipped entirely when the Result is a failure.
     *
     * Unlike {@see flatMap()}, the callback returns a plain value — not a Result.
     * Use {@see flatMap()} when the transformation itself can fail.
     *
     * ```php
     * getUserById($id)
     *     ->transform(fn(UserDTO $user) => $user->name)
     *     ->transform(fn(string $name)  => strtoupper($name));
     * ```
     *
     * @param  callable(T): mixed $fn
     * @return self
     */
    public function transform(callable $fn): self
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
     * Unlike {@see transform()}, the callback must return a Result.
     * Use this when the next step can also fail.
     *
     * Use {@see Result::combine()} instead when steps are independent
     * and you want to collect all errors at once.
     *
     * ```php
     * Result::ok($input)
     *     ->flatMap(fn($input) => validate($input))
     *     ->flatMap(fn($input) => persist($input))
     *     ->transform(fn($user) => new UserDTO($user));
     * ```
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
     * Executes a side-effect with the errors, without altering the Result.
     * Skipped entirely when the Result is successful.
     *
     * The mirror of {@see then()} for the failure path.
     * Use for logging or observing errors mid-pipeline:
     *
     * ```php
     * getUserById($id)
     *     ->orThen(fn($errors) => $logger->warning('User not found', $errors))
     *     ->otherwise(fn($errors) => Result::ok(UserDTO::guest()));
     * ```
     *
     * @param  callable(Error[]): void $fn
     * @return self
     */
    public function orThen(callable $fn): self
    {
        if ($this->isFail()) {
            $fn($this->errors);
        }

        return $this;
    }

    /**
     * Attempts to recover from a failure by returning a new Result.
     * Skipped entirely when the Result is successful.
     *
     * The callback receives the current errors and must return a Result —
     * either a recovered success or a (possibly different) failure.
     *
     * The mirror of {@see flatMap()} for the failure path.
     *
     * ```php
     * fetchFromCache($key)
     *     ->otherwise(fn($errors) => fetchFromDatabase($key))
     *     ->otherwise(fn($errors) => Result::ok($defaultValue));
     * ```
     *
     * @param  callable(Error[]): self $fn
     * @return self
     */
    public function otherwise(callable $fn): self
    {
        if ($this->isFail()) {
            return $fn($this->errors);
        }

        return $this;
    }

    /**
     * Validates the successful value against multiple conditions at once,
     * collecting every error from every failing rule before returning.
     * Skipped entirely when the Result is already a failure.
     *
     * The first failure — ensure() always evaluates every rule, making it
     * ideal when you want to surface all violations in a single pass.
     *
     * Each entry in $rules must be an array with exactly two elements:
     *  - a callable that receives the value and returns bool
     *  - an Error or string to use when that condition fails
     *
     * ```php
     * Result::ok($name)
     *     ->ensure([
     *         [fn($name) => !empty($name),        Error::validation('name', 'Required.')],
     *         [fn($name) => strlen($name) <= 100, Error::validation('name', 'Too long.')],
     *         [fn($name) => ctype_alpha($name),   Error::validation('name', 'Letters only.')],
     *     ])
     *     ->transform(fn($name) => StrHandler::sanitize($name));
     * ```
     *
     * @param  array<array{callable(T): bool, Error|string}> $rules
     * @return self<T>
     */
    public function ensure(array $rules): self
    {
        if ($this->isFail()) {
            return $this;
        }

        $errors = [];

        foreach ($rules as [$condition, $error]) {
            if (!$condition($this->value)) {
                $errors[] = $error instanceof Error ? $error : Error::generic($error);
            }
        }

        return empty($errors) ? $this : new self(false, null, $errors);
    }

    /**
     * Returns the value, or throws a {@see ResultException} if the Result is a failure.
     *
     * Use only when you are certain the Result is successful — for instance,
     * right after a successful {@see combine()} check. Calling this on a
     * failure is a programming error.
     *
     * ```php
     * $user = getUserById($id)->unwrap(); // throws ResultException if not found
     * ```
     *
     * @throws ResultException
     * @return T
     */
    public function unwrap(): mixed
    {
        if ($this->isFail()) {
            throw new ResultException($this->errors);
        }

        return $this->value;
    }

    /**
     * Returns the value if successful; otherwise returns the given default.
     *
     * Use when failure has an acceptable fallback and no handling is needed.
     *
     * ```php
     * $name = getUserById($id)
     *     ->transform(fn($user) => $user->name)
     *     ->default('Anonymous');
     * ```
     *
     * @param  T $default
     * @return T
     */
    public function default(mixed $default): mixed
    {
        return $this->isOk() ? $this->value : $default;
    }

    /**
     * Returns the value if successful; otherwise calls the given callback
     * with the errors and returns null.
     *
     * The callback is responsible for deciding what happens on failure —
     * return an HTTP response, throw, log, redirect, etc.
     * If execution should not continue with null, always exit inside the callback.
     *
     * ```php
     * $user = getUserById($id)
     *     ->unwrapOrHandle(function (array $errors): void {
     *         http_response_code(404);
     *         echo json_encode(['errors' => $errors]);
     *         exit;
     *     });
     * ```
     *
     * @param  callable(Error[]): void $fn
     * @return T|null
     */
    public function unwrapOrHandle(callable $fn): mixed
    {
        if ($this->isFail()) {
            $fn($this->errors);
            return null;
        }

        return $this->value;
    }

    /**
     * Returns a ready-made callback for {@see unwrapOrHandle()} that converts
     * a failed Result into a {@see ResultException}.
     *
     * Useful when you want to re-enter exception-based error handling,
     * for example inside a context that already has a global exception handler.
     *
     * ```php
     * $user = getUserById($id)->unwrapOrHandle(Result::throwOnFail());
     * ```
     *
     * @return callable(Error[]): never
     */
    public static function throwOnFail(): callable
    {
        return function (array $errors): void {
            /** @var Error[] $errors */
            throw new ResultException($errors);
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
     * Pass the value to carry forward as the first argument — typically
     * the original input being validated. On failure, the value is discarded.
     *
     * ```php
     * Result::ok($data)
     *     ->flatMap(fn($data) => Result::combine($data,
     *         !empty($data['name'])  ? Result::ok() : Result::fail(Error::validation('name',  'Required.')),
     *         !empty($data['email']) ? Result::ok() : Result::fail(Error::validation('email', 'Required.')),
     *     ))
     *     ->transform(fn($data) => new User($data['name'], $data['email']));
     * ```
     *
     * When no value needs to be carried (standalone validation), pass null:
     * ```php
     * Result::combine(null, $resultA, $resultB);
     * ```
     *
     * @param  mixed   $value      The value to carry on success.
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