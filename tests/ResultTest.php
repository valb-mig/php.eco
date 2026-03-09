<?php

declare(strict_types=1);

namespace Eco\Tests;

use Eco\{
    Error,
    Result
};
use PHPUnit\Framework\TestCase;
use Eco\Enums\ErrorCode;

final class ResultTest extends TestCase
{
    public function test_ok_carries_value(): void
    {
        $result = Result::ok(42);

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFail());
        $this->assertSame(42, $result->getValue());
    }

    public function test_ok_carries_string(): void
    {
        $this->assertSame('hello', Result::ok('hello')->getValue());
    }

    public function test_ok_carries_array(): void
    {
        $payload = ['a' => 1, 'b' => 2];
        $this->assertSame($payload, Result::ok($payload)->getValue());
    }

    public function test_ok_carries_object(): void
    {
        $obj = new \stdClass();
        $this->assertSame($obj, Result::ok($obj)->getValue());
    }

    public function test_void_is_ok_with_null_value(): void
    {
        $result = Result::void();

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFail());
        $this->assertNull($result->getValue());
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
        $this->assertSame(ErrorCode::GENERIC,     $result->getErrors()[0]->code);
        $this->assertSame(ErrorCode::VALIDATION,  $result->getErrors()[1]->code);
    }

    public function test_get_value_throws_on_failed_result(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot get value from a failed Result.');

        Result::fail('oops')->getValue();
    }

    public function test_get_errors_returns_empty_array_on_success(): void
    {
        $this->assertSame([], Result::ok(1)->getErrors());
    }

    public function test_get_first_error_returns_null_when_no_errors(): void
    {
        $this->assertNull(Result::ok(1)->getErrors()[0]);
    }

    public function test_get_first_error_returns_first_error(): void
    {
        $result = Result::fail(
            Error::validation('name',  'Required.'),
            Error::validation('email', 'Invalid.'),
        );

        $this->assertSame('Required.', $result->getErrors()[0]->message);
    }

    public function test_get_error_messages_returns_all_messages(): void
    {
        $result = Result::fail(
            Error::validation('name',  'Name required.'),
            Error::validation('email', 'Email invalid.'),
        );

        $this->assertSame(['Name required.', 'Email invalid.'], $result->getErrorMessages());
    }

    public function test_map_transforms_value_on_success(): void
    {
        $result = Result::ok(10)->map(fn($n) => $n * 2);

        $this->assertTrue($result->isOk());
        $this->assertSame(20, $result->getValue());
    }

    public function test_map_is_skipped_on_failure(): void
    {
        $called = false;
        $result = Result::fail('error')->map(function ($v) use (&$called) {
            $called = true;
            return $v;
        });

        $this->assertFalse($called);
        $this->assertTrue($result->isFail());
        $this->assertSame(['error'], $result->getErrorMessages());
    }

    public function test_map_can_change_type(): void
    {
        $result = Result::ok(42)->map(fn($n) => "number is {$n}");

        $this->assertSame('number is 42', $result->getValue());
    }

    public function test_flat_map_chains_successful_results(): void
    {
        $result = Result::ok(5)
            ->flatMap(fn($n) => Result::ok($n * 2))
            ->flatMap(fn($n) => Result::ok($n + 1));

        $this->assertSame(11, $result->getValue());
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

    public function test_tap_executes_on_success_and_preserves_value(): void
    {
        $captured = null;

        $result = Result::ok(99)->tap(function ($v) use (&$captured) {
            $captured = $v;
        });

        $this->assertSame(99, $captured);
        $this->assertSame(99, $result->getValue());
    }

    public function test_tap_is_skipped_on_failure(): void
    {
        $called = false;

        $result = Result::fail('error')->tap(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertTrue($result->isFail());
    }

    public function test_on_fail_executes_on_failure_and_preserves_errors(): void
    {
        $captured = [];

        $result = Result::fail(Error::validation('email', 'Invalid.'))
            ->onFail(function (array $errors) use (&$captured) {
                $captured = $errors;
            });

        $this->assertCount(1, $captured);
        $this->assertTrue($result->isFail());
    }

    public function test_on_fail_is_skipped_on_success(): void
    {
        $called = false;

        $result = Result::ok(1)->onFail(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertTrue($result->isOk());
    }

    public function test_recover_returns_new_success_from_failure(): void
    {
        $result = Result::fail('Cache miss')
            ->recover(fn($errors) => Result::ok('fallback value'));

        $this->assertTrue($result->isOk());
        $this->assertSame('fallback value', $result->getValue());
    }

    public function test_recover_is_skipped_on_success(): void
    {
        $called = false;

        $result = Result::ok('original')->recover(function () use (&$called) {
            $called = true;
            return Result::ok('should not reach');
        });

        $this->assertFalse($called);
        $this->assertSame('original', $result->getValue());
    }

    public function test_recover_can_propagate_failure(): void
    {
        $result = Result::fail(Error::make(ErrorCode::GENERIC, 'original'))
            ->recover(fn($errors) => Result::fail(Error::make(ErrorCode::GENERIC, 'still failing')));

        $this->assertTrue($result->isFail());
        $this->assertSame('still failing', $result->getErrors()[0]->message);
    }

    public function test_unwrap_returns_value_on_success(): void
    {
        $this->assertSame('ok', Result::ok('ok')->unwrap());
    }

    public function test_unwrap_throws_on_failure(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unwrap failed: Something went wrong');

        Result::fail('Something went wrong')->unwrap();
    }

    public function test_unwrap_includes_all_messages_in_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Name required., Email invalid.');

        Result::fail('Name required.', 'Email invalid.')->unwrap();
    }

    public function test_unwrap_or_returns_value_on_success(): void
    {
        $this->assertSame('real', Result::ok('real')->unwrapOr('default'));
    }

    public function test_unwrap_or_returns_default_on_failure(): void
    {
        $this->assertSame('default', Result::fail('error')->unwrapOr('default'));
    }

    public function test_unwrap_or_handle_returns_value_on_success(): void
    {
        $result = Result::ok('value')->unwrapOrHandle(fn($e) => null);

        $this->assertSame('value', $result);
    }

    public function test_unwrap_or_handle_calls_handler_on_failure(): void
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

    public function test_combine_returns_ok_with_value_when_all_succeed(): void
    {
        $data   = ['name' => 'Ana', 'email' => 'ana@test.com'];
        $result = Result::combine($data,
            Result::void(),
            Result::void(),
        );

        $this->assertTrue($result->isOk());
        $this->assertSame($data, $result->getValue());
    }

    public function test_combine_collects_all_errors_from_all_failures(): void
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

    public function test_combine_does_not_short_circuit(): void
    {
        $secondEvaluated = false;

        $result = Result::combine(null,
            Result::fail('first'),
            (function () use (&$secondEvaluated) {
                $secondEvaluated = true;
                return Result::fail('second');
            })(),
        );

        $this->assertTrue($secondEvaluated);
        $this->assertCount(2, $result->getErrors());
    }

    public function test_combine_carries_value_through_pipeline(): void
    {
        $data = ['name' => 'João', 'age' => 25];

        $result = Result::ok($data)
            ->flatMap(fn($d) => Result::combine($d,
                !empty($d['name'])   ? Result::void() : Result::fail(Error::validation('name', 'Required.')),
                $d['age'] >= 18      ? Result::void() : Result::fail(Error::validation('age',  'Must be 18+.')),
            ))
            ->map(fn($d) => mb_strtoupper($d['name']));

        $this->assertTrue($result->isOk());
        $this->assertSame('JOÃO', $result->getValue());
    }

    public function test_combine_with_null_value_on_success(): void
    {
        $result = Result::combine(null, Result::void(), Result::void());

        $this->assertTrue($result->isOk());
        $this->assertNull($result->getValue());
    }

    public function test_full_pipeline_success(): void
    {
        $result = Result::ok(['name' => 'Maria', 'email' => 'maria@test.com', 'age' => 30])
            ->flatMap(fn($d) => Result::combine($d,
                !empty($d['name'])                  ? Result::void() : Result::fail(Error::validation('name',  'Required.')),
                str_contains($d['email'], '@')       ? Result::void() : Result::fail(Error::validation('email', 'Invalid.')),
                $d['age'] >= 18                      ? Result::void() : Result::fail(Error::validation('age',   'Must be 18+.')),
            ))
            ->map(fn($d) => ['id' => 1, 'name' => $d['name'], 'email' => $d['email']]);

        $this->assertTrue($result->isOk());
        $this->assertSame('Maria', $result->getValue()['name']);
    }

    public function test_full_pipeline_failure_collects_all_errors(): void
    {
        $result = Result::ok(['name' => '', 'email' => 'invalid', 'age' => 15])
            ->flatMap(fn($d) => Result::combine($d,
                !empty($d['name'])                  ? Result::void() : Result::fail(Error::validation('name',  'Required.')),
                str_contains($d['email'], '@')       ? Result::void() : Result::fail(Error::validation('email', 'Invalid.')),
                $d['age'] >= 18                      ? Result::void() : Result::fail(Error::validation('age',   'Must be 18+.')),
            ))
            ->map(fn($d) => ['id' => 1, 'name' => $d['name']]);

        $this->assertTrue($result->isFail());
        $this->assertCount(3, $result->getErrors());
    }

    public function test_pipeline_with_recover(): void
    {
        $result = Result::fail('primary source failed')
            ->recover(fn($errors) => Result::fail('secondary also failed'))
            ->recover(fn($errors) => Result::ok('fallback'));

        $this->assertTrue($result->isOk());
        $this->assertSame('fallback', $result->getValue());
    }
}