<?php

declare(strict_types=1);

namespace Eco\Tests;

use Eco\Error;
use Eco\Enums\ErrorCode;
use Eco\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function test_ok_carries_integer(): void
    {
        $result = Result::ok(42);

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFail());
        $this->assertSame(42, $result->unwrap());
    }

    public function test_ok_carries_string(): void
    {
        $this->assertSame('hello', Result::ok('hello')->unwrap());
    }

    public function test_ok_carries_array(): void
    {
        $payload = ['a' => 1, 'b' => 2];
        $this->assertSame($payload, Result::ok($payload)->unwrap());
    }

    public function test_ok_carries_object(): void
    {
        $obj = new \stdClass();
        $this->assertSame($obj, Result::ok($obj)->unwrap());
    }

    public function test_ok_carries_null(): void
    {
        $this->assertNull(Result::ok(null)->unwrap());
    }

    public function test_void_is_ok(): void
    {
        $result = Result::void();

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFail());
    }

    public function test_void_carries_null(): void
    {
        $this->assertNull(Result::void()->unwrap());
    }

    public function test_fail_with_string_wraps_in_generic_error(): void
    {
        $result = Result::fail('Something went wrong');

        $this->assertTrue($result->isFail());
        $this->assertFalse($result->isOk());
        $this->assertCount(1, $result->getErrors());
        $this->assertSame('Something went wrong', $result->getErrors()[0]->message);
        $this->assertSame(ErrorCode::GENERIC, $result->getErrors()[0]->code);
    }

    public function test_fail_with_multiple_strings(): void
    {
        $result = Result::fail('Error one', 'Error two', 'Error three');

        $this->assertCount(3, $result->getErrors());
        $this->assertSame(['Error one', 'Error two', 'Error three'], $result->getErrorMessages());
    }

    public function test_fail_with_typed_error(): void
    {
        $error  = Error::validation('email', 'Invalid email.');
        $result = Result::fail($error);

        $this->assertTrue($result->isFail());
        $this->assertSame($error, $result->getErrors()[0]);
    }

    public function test_fail_with_mixed_strings_and_errors(): void
    {
        $result = Result::fail(
            'Generic message',
            Error::validation('name', 'Required.'),
        );

        $this->assertCount(2, $result->getErrors());
        $this->assertSame(ErrorCode::GENERIC,    $result->getErrors()[0]->code);
        $this->assertSame(ErrorCode::VALIDATION, $result->getErrors()[1]->code);
    }

    public function test_get_errors_returns_empty_array_on_success(): void
    {
        $this->assertSame([], Result::ok(1)->getErrors());
    }

    public function test_get_error_messages_returns_all_messages(): void
    {
        $result = Result::fail(
            Error::validation('name',  'Name required.'),
            Error::validation('email', 'Email invalid.'),
        );

        $this->assertSame(['Name required.', 'Email invalid.'], $result->getErrorMessages());
    }

    public function test_then_executes_on_success_and_preserves_value(): void
    {
        $captured = null;

        $result = Result::ok(99)->then(function ($v) use (&$captured) {
            $captured = $v;
        });

        $this->assertSame(99, $captured);
        $this->assertSame(99, $result->unwrap());
    }

    public function test_then_is_skipped_on_failure(): void
    {
        $called = false;

        $result = Result::fail('error')->then(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertTrue($result->isFail());
    }

    public function test_then_is_chainable(): void
    {
        $log = [];

        Result::ok('value')
            ->then(function ($v) use (&$log) { $log[] = "first: {$v}"; })
            ->then(function ($v) use (&$log) { $log[] = "second: {$v}"; });

        $this->assertSame(['first: value', 'second: value'], $log);
    }

    public function test_or_then_executes_on_failure_with_errors(): void
    {
        $captured = [];

        Result::fail(Error::validation('email', 'Invalid.'))
            ->orThen(function (array $errors) use (&$captured) {
                $captured = $errors;
            });

        $this->assertCount(1, $captured);
        $this->assertSame('Invalid.', $captured[0]->message);
    }

    public function test_or_then_is_skipped_on_success(): void
    {
        $called = false;

        $result = Result::ok(1)->orThen(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertTrue($result->isOk());
    }

    public function test_or_then_preserves_the_failure(): void
    {
        $result = Result::fail('error')
            ->orThen(fn($errors) => null); // side-effect only

        $this->assertTrue($result->isFail());
        $this->assertSame(['error'], $result->getErrorMessages());
    }

    public function test_transform_changes_the_value(): void
    {
        $result = Result::ok(10)->transform(fn($n) => $n * 2);

        $this->assertTrue($result->isOk());
        $this->assertSame(20, $result->unwrap());
    }

    public function test_transform_can_change_type(): void
    {
        $result = Result::ok(42)->transform(fn($n) => "number is {$n}");

        $this->assertSame('number is 42', $result->unwrap());
    }

    public function test_transform_is_skipped_on_failure(): void
    {
        $called = false;

        $result = Result::fail('error')->transform(function ($v) use (&$called) {
            $called = true;
            return $v;
        });

        $this->assertFalse($called);
        $this->assertTrue($result->isFail());
        $this->assertSame(['error'], $result->getErrorMessages());
    }

    public function test_transform_is_chainable(): void
    {
        $result = Result::ok('  hello  ')
            ->transform(fn($s) => trim($s))
            ->transform(fn($s) => mb_strtoupper($s));

        $this->assertSame('HELLO', $result->unwrap());
    }

    public function test_flat_map_chains_successful_results(): void
    {
        $result = Result::ok(5)
            ->flatMap(fn($n) => Result::ok($n * 2))
            ->flatMap(fn($n) => Result::ok($n + 1));

        $this->assertSame(11, $result->unwrap());
    }

    public function test_flat_map_short_circuits_on_first_failure(): void
    {
        $secondCalled = false;

        $result = Result::ok(5)
            ->flatMap(fn($n) => Result::fail('first failure'))
            ->flatMap(function ($n) use (&$secondCalled) {
                $secondCalled = true;
                return Result::ok($n);
            });

        $this->assertFalse($secondCalled);
        $this->assertTrue($result->isFail());
        $this->assertSame(['first failure'], $result->getErrorMessages());
    }

    public function test_flat_map_is_skipped_on_initial_failure(): void
    {
        $called = false;

        Result::fail('error')->flatMap(function ($v) use (&$called) {
            $called = true;
            return Result::ok($v);
        });

        $this->assertFalse($called);
    }

    public function test_otherwise_recovers_from_failure(): void
    {
        $result = Result::fail('Cache miss')
            ->otherwise(fn($errors) => Result::ok('fallback value'));

        $this->assertTrue($result->isOk());
        $this->assertSame('fallback value', $result->unwrap());
    }

    public function test_otherwise_is_skipped_on_success(): void
    {
        $called = false;

        $result = Result::ok('original')->otherwise(function () use (&$called) {
            $called = true;
            return Result::ok('should not reach');
        });

        $this->assertFalse($called);
        $this->assertSame('original', $result->unwrap());
    }

    public function test_otherwise_can_propagate_a_different_failure(): void
    {
        $result = Result::fail('original error')
            ->otherwise(fn($errors) => Result::fail('different error'));

        $this->assertTrue($result->isFail());
        $this->assertSame(['different error'], $result->getErrorMessages());
    }

    public function test_otherwise_receives_the_errors(): void
    {
        $captured = [];

        Result::fail(Error::validation('email', 'Invalid.'))
            ->otherwise(function (array $errors) use (&$captured) {
                $captured = $errors;
                return Result::void();
            });

        $this->assertCount(1, $captured);
        $this->assertSame('Invalid.', $captured[0]->message);
    }

    public function test_otherwise_is_chainable_for_cascading_fallbacks(): void
    {
        $result = Result::fail('primary failed')
            ->otherwise(fn($errors) => Result::fail('secondary failed'))
            ->otherwise(fn($errors) => Result::ok('last resort'));

        $this->assertTrue($result->isOk());
        $this->assertSame('last resort', $result->unwrap());
    }

    public function test_unwrap_returns_value_on_success(): void
    {
        $this->assertSame('ok', Result::ok('ok')->unwrap());
    }

    public function test_unwrap_throws_logic_exception_on_failure(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unwrap failed: Something went wrong');

        Result::fail('Something went wrong')->unwrap();
    }

    public function test_unwrap_includes_all_messages_when_throwing(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Name required., Email invalid.');

        Result::fail('Name required.', 'Email invalid.')->unwrap();
    }

    public function test_or_returns_value_on_success(): void
    {
        $this->assertSame('real', Result::ok('real')->or('default'));
    }

    public function test_or_returns_default_on_failure(): void
    {
        $this->assertSame('default', Result::fail('error')->or('default'));
    }

    public function test_or_returns_null_default(): void
    {
        $this->assertNull(Result::fail('error')->or(null));
    }

    public function test_unwrap_or_handle_returns_value_on_success(): void
    {
        $result = Result::ok('value')->unwrapOrHandle(fn($e) => null);

        $this->assertSame('value', $result);
    }

    public function test_unwrap_or_handle_calls_handler_and_returns_null_on_failure(): void
    {
        $captured = [];

        $result = Result::fail(Error::validation('age', 'Must be 18+.'))
            ->unwrapOrHandle(function (array $errors) use (&$captured) {
                $captured = $errors;
            });

        $this->assertNull($result);
        $this->assertCount(1, $captured);
        $this->assertSame('Must be 18+.', $captured[0]->message);
    }

    public function test_throw_on_fail_throws_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Something broke');

        Result::fail('Something broke')->unwrapOrHandle(Result::throwOnFail());
    }

    public function test_throw_on_fail_joins_multiple_messages(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error one, Error two');

        Result::fail('Error one', 'Error two')->unwrapOrHandle(Result::throwOnFail());
    }

    public function test_throw_on_fail_is_skipped_on_success(): void
    {
        $value = Result::ok('safe')->unwrapOrHandle(Result::throwOnFail());

        $this->assertSame('safe', $value);
    }

    public function test_combine_returns_ok_carrying_value_when_all_succeed(): void
    {
        $data   = ['name' => 'Ana', 'email' => 'ana@test.com'];
        $result = Result::combine($data, Result::void(), Result::void());

        $this->assertTrue($result->isOk());
        $this->assertSame($data, $result->unwrap());
    }

    public function test_combine_collects_all_errors_without_short_circuiting(): void
    {
        $result = Result::combine(null,
            Result::fail(Error::validation('name',  'Required.')),
            Result::ok(42),
            Result::fail(Error::validation('email', 'Invalid.')),
            Result::fail(Error::validation('age',   'Must be 18+.')),
        );

        $this->assertTrue($result->isFail());
        $this->assertCount(3, $result->getErrors());
        $this->assertSame(
            ['Required.', 'Invalid.', 'Must be 18+.'],
            $result->getErrorMessages(),
        );
    }

    public function test_combine_evaluates_all_results_even_after_failure(): void
    {
        $secondEvaluated = false;

        Result::combine(null,
            Result::fail('first'),
            (function () use (&$secondEvaluated) {
                $secondEvaluated = true;
                return Result::fail('second');
            })(),
        );

        $this->assertTrue($secondEvaluated);
    }

    public function test_combine_with_null_value_on_success(): void
    {
        $result = Result::combine(null, Result::void(), Result::void());

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrap());
    }

    public function test_combine_carries_value_through_pipeline(): void
    {
        $data = ['name' => 'João', 'age' => 25];

        $result = Result::ok($data)
            ->flatMap(fn($d) => Result::combine($d,
                !empty($d['name']) ? Result::void() : Result::fail(Error::validation('name', 'Required.')),
                $d['age'] >= 18    ? Result::void() : Result::fail(Error::validation('age',  'Must be 18+.')),
            ))
            ->transform(fn($d) => mb_strtoupper($d['name']));

        $this->assertTrue($result->isOk());
        $this->assertSame('JOÃO', $result->unwrap());
    }

    public function test_then_and_or_then_are_mutually_exclusive(): void
    {
        $thenCalled   = false;
        $orThenCalled = false;

        Result::ok('value')
            ->then(function () use (&$thenCalled)   { $thenCalled   = true; })
            ->orThen(function () use (&$orThenCalled) { $orThenCalled = true; });

        $this->assertTrue($thenCalled);
        $this->assertFalse($orThenCalled);

        $thenCalled   = false;
        $orThenCalled = false;

        Result::fail('error')
            ->then(function () use (&$thenCalled)   { $thenCalled   = true; })
            ->orThen(function () use (&$orThenCalled) { $orThenCalled = true; });

        $this->assertFalse($thenCalled);
        $this->assertTrue($orThenCalled);
    }

    public function test_transform_and_otherwise_are_mutually_exclusive(): void
    {
        $transformCalled  = false;
        $otherwiseCalled  = false;

        Result::ok('value')
            ->transform(function ($v) use (&$transformCalled)  { $transformCalled  = true; return $v; })
            ->otherwise(function ($e) use (&$otherwiseCalled)  { $otherwiseCalled  = true; return Result::void(); });

        $this->assertTrue($transformCalled);
        $this->assertFalse($otherwiseCalled);

        $transformCalled  = false;
        $otherwiseCalled  = false;

        Result::fail('error')
            ->transform(function ($v) use (&$transformCalled)  { $transformCalled  = true; return $v; })
            ->otherwise(function ($e) use (&$otherwiseCalled)  { $otherwiseCalled  = true; return Result::void(); });

        $this->assertFalse($transformCalled);
        $this->assertTrue($otherwiseCalled);
    }

    public function test_full_pipeline_success(): void
    {
        $result = Result::ok(['name' => 'Maria', 'email' => 'maria@test.com', 'age' => 30])
            ->flatMap(fn($d) => Result::combine($d,
                !empty($d['name'])             ? Result::void() : Result::fail(Error::validation('name',  'Required.')),
                str_contains($d['email'], '@') ? Result::void() : Result::fail(Error::validation('email', 'Invalid.')),
                $d['age'] >= 18                ? Result::void() : Result::fail(Error::validation('age',   'Must be 18+.')),
            ))
            ->then(fn($d)    => null /* log */)
            ->transform(fn($d) => ['id' => 1, 'name' => $d['name'], 'email' => $d['email']]);

        $this->assertTrue($result->isOk());
        $this->assertSame('Maria', $result->unwrap()['name']);
    }

    public function test_full_pipeline_failure_collects_all_errors(): void
    {
        $result = Result::ok(['name' => '', 'email' => 'invalid', 'age' => 15])
            ->flatMap(fn($d) => Result::combine($d,
                !empty($d['name'])             ? Result::void() : Result::fail(Error::validation('name',  'Required.')),
                str_contains($d['email'], '@') ? Result::void() : Result::fail(Error::validation('email', 'Invalid.')),
                $d['age'] >= 18                ? Result::void() : Result::fail(Error::validation('age',   'Must be 18+.')),
            ))
            ->transform(fn($d) => ['id' => 1, 'name' => $d['name']]);

        $this->assertTrue($result->isFail());
        $this->assertCount(3, $result->getErrors());
    }

    public function test_pipeline_with_otherwise_recovery(): void
    {
        $log = [];

        $result = Result::fail('primary source failed')
            ->orThen(function($errors) use (&$log) {
                $log[] = 'logged: ' . $errors[0]->message;
            })
            ->otherwise(fn($errors) => Result::fail('secondary also failed'))
            ->otherwise(fn($errors) => Result::ok('fallback'))
            ->transform(fn($v)    => mb_strtoupper($v));

        $this->assertTrue($result->isOk());
        $this->assertSame('FALLBACK', $result->unwrap());
        $this->assertSame(['logged: primary source failed'], $log);
    }

    public function test_pipeline_unwrap_or_handle_with_exit_simulation(): void
    {
        $handlerCalled = false;
        $errors        = [];

        $result = Result::fail(Error::validation('email', 'Invalid.'))
            ->unwrapOrHandle(function (array $e) use (&$handlerCalled, &$errors) {
                $handlerCalled = true;
                $errors        = $e;
                // in real code: http_response_code(422); exit;
            });

        $this->assertNull($result);
        $this->assertTrue($handlerCalled);
        $this->assertSame('Invalid.', $errors[0]->message);
    }
}