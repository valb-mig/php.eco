# eco

A lightweight PHP library for handling results and errors without exceptions.

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-76%20passing-brightgreen)]()
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
    ->transform(fn($user) => $user->toArray())
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
- **`ErrorCode`** — a built-in enum with universal codes (`GENERIC`, `VALIDATION`). Bring your own enum for domain-specific codes.

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

### Accessing errors

```php
$result->getErrors();        // Error[]
$result->getErrorMessages(); // string[]
```

---

## Pipeline

The pipeline has two parallel tracks — the **ok path** and the **fail path**. Each method only runs on its track and leaves the other untouched.

```
ok path  ──→  then()  ──→  transform()  ──→  flatMap()  ──→
fail path ──→  orThen() ──→  otherwise()  ──────────────────→
```

| Method | Runs when | Alters Result? | Use for |
|---|---|---|---|
| `then()` | ok | no | Side-effect with the value |
| `orThen()` | fail | no | Side-effect with the errors |
| `transform()` | ok | yes — new value | Transform the carried value |
| `flatMap()` | ok | yes — new Result | Chain a Result-returning operation |
| `otherwise()` | fail | yes — new Result | Recover from failure |

### `then` and `orThen` — side-effects

```php
getUserById($id)
    ->then(fn($user)     => $logger->info("Loaded: {$user->name}"))  // ok path
    ->orThen(fn($errors) => $logger->warning('Not found', $errors)); // fail path
```

### `transform` — change the value

```php
getUserById($id)
    ->transform(fn(UserDTO $user) => $user->name)
    ->transform(fn(string $name)  => mb_strtoupper($name))
    ->or('ANONYMOUS');
```

### `flatMap` — chain operations that can also fail

Unlike `transform`, the callback must return a `Result`.
Short-circuits on the first failure — subsequent steps are skipped.

```php
Result::ok($input)
    ->flatMap(fn($input) => validate($input))  // can fail
    ->flatMap(fn($input) => persist($input))   // can fail
    ->transform(fn($user) => new UserDTO($user));
```

### `otherwise` — recover from failure

```php
fetchFromCache($key)
    ->otherwise(fn($errors) => fetchFromDatabase($key))
    ->otherwise(fn($errors) => Result::ok($defaultValue));
```

### Full pipeline example

```php
getUserById(999)
    ->then(fn($user)     => dump("[LOG] found: {$user->name}"))
    ->orThen(fn($errors) => dump('[LOG] user not found'))
    ->otherwise(fn($errors) => Result::ok(UserDTO::guest()))
    ->then(fn($user)     => dump('[LOG] continuing with user'))
    ->transform(fn(UserDTO $user) => $user->name)
    ->or('Anonymous');
```

---

## Unwrap — exiting the pipeline

| Method | Returns on ok | Returns on fail |
|---|---|---|
| `unwrap()` | value | throws `LogicException` |
| `or($default)` | value | `$default` |
| `unwrapOrHandle($fn)` | value | calls `$fn(errors)`, returns `null` |

```php
// Throws if failed — use only when certain it succeeded
$value = $result->unwrap();

// Returns value or a fallback
$name = $result->or('Anonymous');

// Delegates failure handling to the caller
$user = $result->unwrapOrHandle(function (array $errors): void {
    http_response_code(422);
    echo json_encode(['errors' => $errors]);
    exit;
});

// Converts failure into a RuntimeException
$user = $result->unwrapOrHandle(Result::throwOnFail());
```

---

## Combine

Use `combine()` when you want to run **all validations at once** and collect every error — unlike `flatMap` which stops at the first failure.

Pass the value to carry forward as the first argument.

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
        ->transform(fn($data) => new User($data['name'], $data['email']));
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
                !empty($input['product_id'])   ? Result::void() : Result::fail(Error::validation('product_id', 'Required.')),
                ($input['quantity'] ?? 0) > 0  ? Result::void() : Result::fail(Error::validation('quantity',   'Must be greater than 0.')),
            ))
            ->flatMap(fn($input)   => $this->products->find($input['product_id']))
            ->then(fn($product)    => $this->logger->info("Creating order for {$product->name}"))
            ->flatMap(fn($product) => $this->orders->create($product, $input['quantity']))
            ->orThen(fn($errors)   => $this->logger->warning('Order creation failed', [
                'errors' => array_map(fn($e) => $e->toArray(), $errors),
            ]));
    }
}

// In your controller
$order = $handler->handle($request->all())
    ->unwrapOrHandle(function (array $errors): void {
        http_response_code(422);
        echo json_encode(['errors' => array_map(fn($e) => $e->toArray(), $errors)]);
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