<?php

declare(strict_types=1);

namespace Eco\Tests;

use Eco\Contracts\ErrorCodeContract;
use Eco\Enums\ErrorCode;
use Eco\Error;
use PHPUnit\Framework\TestCase;

final class ErrorTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        $error = new Error(ErrorCode::GENERIC, 'Something went wrong', 'field_name');

        $this->assertSame(ErrorCode::GENERIC, $error->code);
        $this->assertSame('Something went wrong', $error->message);
        $this->assertSame('field_name', $error->field);
    }

    public function test_constructor_field_defaults_to_empty_string(): void
    {
        $error = new Error(ErrorCode::GENERIC, 'msg');

        $this->assertSame('', $error->field);
    }

    public function test_make_creates_error_with_given_code_and_message(): void
    {
        $error = Error::make(ErrorCode::GENERIC, 'Custom error.');

        $this->assertSame(ErrorCode::GENERIC, $error->code);
        $this->assertSame('Custom error.', $error->message);
        $this->assertSame('', $error->field);
    }

    public function test_make_accepts_field(): void
    {
        $error = Error::make(ErrorCode::VALIDATION, 'Invalid.', 'email');

        $this->assertSame('email', $error->field);
    }

    public function test_make_accepts_custom_error_code_contract(): void
    {
        $customCode = new class implements ErrorCodeContract {
            public function value(): string { return 'CUSTOM_CODE'; }
        };

        $error = Error::make($customCode, 'Domain error.');

        $this->assertSame('CUSTOM_CODE', $error->code->value());
        $this->assertSame('Domain error.', $error->message);
    }

    public function test_make_accepts_domain_enum(): void
    {
        $error = Error::make(AppErrorCode::UNAUTHORIZED, 'Access denied.');

        $this->assertSame('UNAUTHORIZED', $error->code->value());
        $this->assertSame('Access denied.', $error->message);
    }

    public function test_generic_sets_generic_code(): void
    {
        $error = Error::generic('Unexpected error.');

        $this->assertSame(ErrorCode::GENERIC, $error->code);
        $this->assertSame('GENERIC_ERROR', $error->code->value());
    }

    public function test_generic_sets_message(): void
    {
        $error = Error::generic('Something failed.');

        $this->assertSame('Something failed.', $error->message);
    }

    public function test_generic_has_no_field(): void
    {
        $this->assertSame('', Error::generic('msg')->field);
    }

    public function test_validation_sets_validation_code(): void
    {
        $error = Error::validation('email', 'Invalid email.');

        $this->assertSame(ErrorCode::VALIDATION, $error->code);
        $this->assertSame('VALIDATION_ERROR', $error->code->value());
    }

    public function test_validation_sets_field_and_message(): void
    {
        $error = Error::validation('email', 'Must be a valid e-mail address.');

        $this->assertSame('email', $error->field);
        $this->assertSame('Must be a valid e-mail address.', $error->message);
    }

    public function test_validation_different_fields(): void
    {
        $name  = Error::validation('name',  'Required.');
        $age   = Error::validation('age',   'Must be at least 18.');
        $phone = Error::validation('phone', 'Invalid format.');

        $this->assertSame('name',  $name->field);
        $this->assertSame('age',   $age->field);
        $this->assertSame('phone', $phone->field);
    }

    public function test_to_array_includes_code_and_message(): void
    {
        $array = Error::generic('Something went wrong.')->toArray();

        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertSame('GENERIC_ERROR', $array['code']);
        $this->assertSame('Something went wrong.', $array['message']);
    }

    public function test_to_array_includes_field_when_present(): void
    {
        $array = Error::validation('email', 'Invalid.')->toArray();

        $this->assertArrayHasKey('field', $array);
        $this->assertSame('email', $array['field']);
    }

    public function test_to_array_omits_field_when_empty(): void
    {
        $array = Error::generic('msg')->toArray();

        $this->assertArrayNotHasKey('field', $array);
    }

    public function test_to_array_validation_structure(): void
    {
        $array = Error::validation('email', 'Must be a valid e-mail.')->toArray();

        $this->assertSame([
            'code'    => 'VALIDATION_ERROR',
            'message' => 'Must be a valid e-mail.',
            'field'   => 'email',
        ], $array);
    }

    public function test_to_array_generic_structure(): void
    {
        $array = Error::generic('Unexpected error.')->toArray();

        $this->assertSame([
            'code'    => 'GENERIC_ERROR',
            'message' => 'Unexpected error.',
        ], $array);
    }

    public function test_to_array_with_custom_code(): void
    {
        $array = Error::make(AppErrorCode::UNAUTHORIZED, 'Access denied.')->toArray();

        $this->assertSame('UNAUTHORIZED', $array['code']);
        $this->assertSame('Access denied.', $array['message']);
    }

    public function test_to_string_without_field(): void
    {
        $error = Error::generic('Access denied.');

        $this->assertSame('Access denied. (GENERIC_ERROR)', (string) $error);
    }

    public function test_to_string_with_field(): void
    {
        $error = Error::validation('email', 'Invalid email.');

        $this->assertSame('[email] Invalid email. (VALIDATION_ERROR)', (string) $error);
    }

    public function test_to_string_with_custom_code(): void
    {
        $error = Error::make(AppErrorCode::UNAUTHORIZED, 'Forbidden.');

        $this->assertSame('Forbidden. (UNAUTHORIZED)', (string) $error);
    }

    public function test_to_string_with_custom_code_and_field(): void
    {
        $error = Error::make(AppErrorCode::UNAUTHORIZED, 'Forbidden.', 'token');

        $this->assertSame('[token] Forbidden. (UNAUTHORIZED)', (string) $error);
    }

    public function test_two_errors_with_same_data_are_independent(): void
    {
        $a = Error::validation('email', 'Invalid.');
        $b = Error::validation('email', 'Invalid.');

        $this->assertNotSame($a, $b);
        $this->assertSame($a->message, $b->message);
        $this->assertSame($a->field,   $b->field);
        $this->assertSame($a->code,    $b->code);
    }
}

enum AppErrorCode: string implements ErrorCodeContract
{
    case UNAUTHORIZED         = 'UNAUTHORIZED';
    case INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';

    public function value(): string
    {
        return $this->value;
    }
}