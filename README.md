# eco

A lightweight PHP library for handling results and errors without exceptions.

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-67%20passing-brightgreen)]()
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)]()

---

## Why

PHP handles failures in two ways: return values you have to remember to check, or exceptions you have to remember to catch. Neither scales well across a large codebase.

**eco** gives you a third way — a `Result` type that makes failure explicit, composable, and impossible to ignore.

```php
// Before — easy to forget to check
$user = $repo->find($id); // null? false? throws?

// After — failure is part of the type
$result = $repo->find($id); // Result<User>

$result
    ->map(fn($user) => $user->toArray())
    ->unwrapOrHandle(fn($errors) => response()->json($errors, 422));
```

---

## Installation

```bash
composer require valb/eco
```

**Requirements:** PHP 8.1+

---

## Core concepts

eco has three classes:

- **`Result<T>`** — represents the outcome of an operation. Either `ok` (carries a value) or `fail` (carries errors).
- **`Error`** — an immutable error with a machine-readable code, a human-readable message, and an optional field.
- **`ErrorCode`** — a built-in enum with universal codes (`GENERIC`, `VALIDATION`). Extend it with your own enum for domain-specific codes.

---

## Result

### Creating results

```php
use Eco\Result;
use Eco\Error;

// Success with a value
Result::ok($user);
Result::ok(['id' => 1, 'name' => 'Ana']);

// Success without a value (delete, update, send email...)
Result::void();

// Failure — plain string is auto-wrapped in Error::generic()
Result::fail('Something went wrong.');

// Failure — typed errors
Result::fail(Error::validation('email', 'Invalid format.'));

// Failure — multiple errors at once
Result::fail(
    Error::validation('name',  'Required.'),
    Error::validation('email', 'Invalid format.'),
);
```

### Checking state

```php
$result->isOk();   // true when successful
$result->isFail(); // true when failed
```

### Extracting the value

```php
// Throws LogicException if failed — use only when you're certain it succeeded
$value = $result->unwrap();

// Returns value or a default fallback
$name = $result->unwrapOr('Anonymous');

// Delegates failure handling to the caller
$user = $result->unwrapOrHandle(function (array $errors): void {
    http_response_code(422);
    echo json_encode(['errors' => $errors]);
    exit;
});

// Converts failure into a RuntimeException
$user = $result->unwrapOrHandle(Result::throwOnFail());
```

### Accessing errors

```php
$result->getErrors();        // Error[]
$result->getErrorMessages(); // string[]
```

---

## Pipeline

Chain operations without breaking the flow. Each step only runs if the previous one succeeded.

```php
Result::ok($rawInput)
    ->flatMap(fn($input) => validate($input))   // returns Result
    ->flatMap(fn($input) => persist($input))    // returns Result
    ->tap(fn($user) => $cache->store($user))    // side-effect, value unchanged
    ->map(fn($user) => new UserDTO($user))      // transforms the value
    ->onFail(fn($errors) => $logger->warning($errors)) // side-effect on failure
    ->unwrapOrHandle(fn($errors) => response(422, $errors));
```

| Method | Runs when | Returns | Use for |
|---|---|---|---|
| `map` | success | `Result` with transformed value | Transforming the value |
| `flatMap` | success | `Result` from callback | Chaining operations |
| `tap` | success | same `Result` unchanged | Logging, caching, events |
| `onFail` | failure | same `Result` unchanged | Logging errors |
| `recover` | failure | new `Result` from callback | Fallbacks |

### `map` — transform the value

```php
$result = Result::ok(21)->map(fn($n) => $n * 2);
$result->getValue(); // 42
```

### `flatMap` — chain operations (short-circuits on first failure)

```php
function parseAge(mixed $raw): Result
{
    if (!is_numeric($raw)) {
        return Result::fail(Error::validation('age', 'Must be a number.'));
    }
    return Result::ok((int) $raw);
}

function validateAdult(int $age): Result
{
    if ($age < 18) {
        return Result::fail(Error::validation('age', 'Must be at least 18.'));
    }
    return Result::ok($age);
}

Result::ok('25')
    ->flatMap(fn($v) => parseAge($v))      // ok(25)
    ->flatMap(fn($age) => validateAdult($age)); // ok(25)

Result::ok('abc')
    ->flatMap(fn($v) => parseAge($v))      // fail — stops here
    ->flatMap(fn($age) => validateAdult($age)); // never runs
```

### `tap` — side-effects on success

```php
Result::ok($user)
    ->tap(fn($user) => $cache->store('user:'.$user->id, $user))
    ->flatMap(fn($user) => sendWelcomeEmail($user));
```

### `recover` — fallback on failure

```php
fetchFromCache($key)
    ->recover(fn($errors) => fetchFromDatabase($key))
    ->recover(fn($errors) => Result::ok($defaultValue));
```

---

## Combine

Use `combine()` when you want to run **all validations at once** and collect every error — unlike `flatMap` which stops at the first failure.

The first argument is the value to carry forward on success.

```php
function registerUser(array $data): Result
{
    return Result::ok($data)
        ->flatMap(fn($data) => Result::combine($data,
            !empty($data['name'])                   ? Result::void() : Result::fail(Error::validation('name',  'Required.')),
            !empty($data['email'])                  ? Result::void() : Result::fail(Error::validation('email', 'Required.')),
            str_contains($data['email'] ?? '', '@') ? Result::void() : Result::fail(Error::validation('email', 'Invalid format.')),
            ($data['age'] ?? 0) >= 18               ? Result::void() : Result::fail(Error::validation('age',   'Must be 18+.')),
        ))
        ->map(fn($data) => new User($data['name'], $data['email']));
}

// All errors collected at once
$result = registerUser(['name' => '', 'email' => 'invalid', 'age' => 15]);
$result->getErrorMessages();
// ['Required.', 'Required.', 'Invalid format.', 'Must be 18+.']
```

When no value needs to be carried, pass `null` explicitly:

```php
Result::combine(null, $resultA, $resultB, $resultC);
```

---

## Error

### Built-in factories

```php
use Eco\Error;

// Generic — unclassified error
Error::generic('Unexpected error.');

// Validation — tied to an input field
Error::validation('email', 'Must be a valid e-mail address.');
Error::validation('age',   'Must be at least 18.');
```

### Custom domain errors with `make()`

For errors beyond the built-in codes, implement `ErrorCodeContract` on your own enum:

```php
use Eco\Contracts\ErrorCodeContract;

enum AppErrorCode: string implements ErrorCodeContract
{
    case UNAUTHORIZED         = 'UNAUTHORIZED';
    case INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';
    case ORDER_CANCELLED      = 'ORDER_ALREADY_CANCELLED';

    public function value(): string
    {
        return $this->value;
    }
}

// Then use Error::make() with your codes
Error::make(AppErrorCode::UNAUTHORIZED,         'Access denied.');
Error::make(AppErrorCode::INSUFFICIENT_BALANCE, 'Not enough credits.', 'balance');
```

### Serialization

```php
$error = Error::validation('email', 'Invalid format.');

$error->toArray();
// ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid format.', 'field' => 'email']

(string) $error;
// '[email] Invalid format. (VALIDATION_ERROR)'
```

### Comparing codes

```php
use Eco\Enums\ErrorCode;

if ($result->getErrors()[0]->code === ErrorCode::VALIDATION) {
    // handle validation error
}

if ($result->getErrors()[0]->code === AppErrorCode::UNAUTHORIZED) {
    // redirect to login
}
```

---

## Real-world example

```php
class CreateOrderHandler
{
    public function handle(array $input): Result
    {
        return Result::ok($input)
            ->flatMap(fn($input) => Result::combine($input,
                !empty($input['product_id']) ? Result::void() : Result::fail(Error::validation('product_id', 'Required.')),
                ($input['quantity'] ?? 0) > 0 ? Result::void() : Result::fail(Error::validation('quantity', 'Must be greater than 0.')),
            ))
            ->flatMap(fn($input) => $this->products->find($input['product_id']))
            ->tap(fn($product) => $this->logger->info("Creating order for {$product->name}"))
            ->flatMap(fn($product) => $this->orders->create($product, $input['quantity']))
            ->onFail(fn($errors) => $this->logger->warning('Order creation failed', [
                'errors' => array_map(fn($e) => $e->toArray(), $errors),
            ]));
    }
}

// In your controller
$result = $handler->handle($request->all());

$order = $result->unwrapOrHandle(function (array $errors): void {
    http_response_code(422);
    echo json_encode([
        'errors' => array_map(fn($e) => $e->toArray(), $errors),
    ]);
    exit;
});
```

---

## Testing

```bash
# Run tests
composer test

# Coverage report in terminal
composer test:coverage-text

# Coverage report in browser
composer test:coverage
open coverage/index.html
```

---

## License

MIT — see [LICENSE](LICENSE).