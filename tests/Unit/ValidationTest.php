<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\DataObject;
use BetterData\Exception\ValidationException;
use BetterData\Tests\Fixtures\ValidatedOrderDto;
use BetterData\Tests\Fixtures\ValidatedUserDto;
use BetterData\Validation\Rule\Required;
use BetterData\Validation\ValidationEngineInterface;
use BetterData\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testValidDtoProducesEmptyResult(): void
    {
        $dto = ValidatedUserDto::fromArray([
            'email' => 'jane@example.com',
            'name' => 'Jane',
            'age' => 30,
            'role' => 'admin',
        ]);

        $result = $dto->validate();

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors);
    }

    public function testEmailRuleFailsOnBadValue(): void
    {
        $dto = ValidatedUserDto::fromArray(['email' => 'not-an-email', 'name' => 'Jane']);
        $result = $dto->validate();

        self::assertFalse($result->isValid());
        self::assertSame('must be a valid email address', $result->firstError('email'));
    }

    public function testRequiredRuleShortCircuitsLaterRulesOnSameField(): void
    {
        $dto = ValidatedUserDto::fromArray(['email' => '', 'name' => 'Jane']);
        $result = $dto->validate();

        // only Required fires; Email is skipped because short-circuited
        self::assertCount(1, $result->errorsFor('email'));
        self::assertSame('must not be blank', $result->firstError('email'));
    }

    public function testMinLengthAndMaxLengthRules(): void
    {
        $tooShort = ValidatedUserDto::fromArray(['email' => 'a@b.co', 'name' => 'J']);
        self::assertSame('must be at least 2 characters', $tooShort->validate()->firstError('name'));

        $tooLong = ValidatedUserDto::fromArray(['email' => 'a@b.co', 'name' => str_repeat('x', 51)]);
        self::assertSame('must not exceed 50 characters', $tooLong->validate()->firstError('name'));
    }

    public function testMinMaxNumericRules(): void
    {
        $underMin = ValidatedUserDto::fromArray(['email' => 'a@b.co', 'name' => 'x', 'age' => -1]);
        self::assertSame('must be at least 0', $underMin->validate()->firstError('age'));

        $overMax = ValidatedUserDto::fromArray(['email' => 'a@b.co', 'name' => 'xy', 'age' => 200]);
        self::assertSame('must not be greater than 150', $overMax->validate()->firstError('age'));
    }

    public function testOneOfRule(): void
    {
        $dto = ValidatedUserDto::fromArray([
            'email' => 'a@b.co',
            'name' => 'xy',
            'role' => 'ghost',
        ]);

        $result = $dto->validate();

        self::assertStringContainsString('must be one of', $result->firstError('role') ?? '');
    }

    public function testNullableFieldsSkipRulesExceptRequired(): void
    {
        $dto = ValidatedUserDto::fromArray([
            'email' => 'a@b.co',
            'name' => 'xy',
        ]);

        $result = $dto->validate();

        self::assertTrue($result->isValid(), 'Null website/externalId/phone should pass non-Required rules');
    }

    public function testRegexRuleUsesCustomMessage(): void
    {
        $dto = ValidatedUserDto::fromArray([
            'email' => 'a@b.co',
            'name' => 'xy',
            'phone' => '123-abc',
        ]);

        self::assertSame('must be an E.164 phone number', $dto->validate()->firstError('phone'));
    }

    public function testUuidRule(): void
    {
        $dto = ValidatedUserDto::fromArray([
            'email' => 'a@b.co',
            'name' => 'xy',
            'externalId' => 'not-a-uuid',
        ]);

        self::assertSame('must be a valid UUID', $dto->validate()->firstError('externalId'));

        $valid = ValidatedUserDto::fromArray([
            'email' => 'a@b.co',
            'name' => 'xy',
            'externalId' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        self::assertTrue($valid->validate()->isValid());
    }

    public function testUrlRule(): void
    {
        $bad = ValidatedUserDto::fromArray([
            'email' => 'a@b.co',
            'name' => 'xy',
            'website' => 'not a url',
        ]);

        self::assertSame('must be a valid URL', $bad->validate()->firstError('website'));
    }

    public function testNestedDtoValidationUsesDotPath(): void
    {
        $order = ValidatedOrderDto::fromArray([
            'orderNumber' => 'ORD-1',
            'total' => 99.95,
            'customer' => [
                'email' => 'broken',
                'name' => 'J',
            ],
        ]);

        $result = $order->validate();

        self::assertFalse($result->isValid());
        self::assertSame('must be a valid email address', $result->firstError('customer.email'));
        self::assertSame('must be at least 2 characters', $result->firstError('customer.name'));
    }

    public function testFromArrayValidatedThrowsOnInvalid(): void
    {
        try {
            ValidatedUserDto::fromArrayValidated([
                'email' => 'bad',
                'name' => 'Jane',
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
            self::assertSame('must be a valid email address', $e->result->firstError('email'));
        }
    }

    public function testFromArrayValidatedReturnsDtoOnValid(): void
    {
        $dto = ValidatedUserDto::fromArrayValidated([
            'email' => 'ok@example.com',
            'name' => 'Ok',
        ]);

        self::assertSame('ok@example.com', $dto->email);
    }

    public function testCustomValidationEngineCanBeInjected(): void
    {
        $engine = new class () implements ValidationEngineInterface {
            public function validate(DataObject $subject): ValidationResult
            {
                return new ValidationResult(['custom' => ['always fails']]);
            }
        };

        $dto = ValidatedUserDto::fromArray(['email' => 'ok@example.com', 'name' => 'Ok']);
        $result = $dto->validate($engine);

        self::assertFalse($result->isValid());
        self::assertSame('always fails', $result->firstError('custom'));
    }

    public function testValidationResultFlatten(): void
    {
        $result = new ValidationResult([
            'email' => ['must be a valid email address'],
            'name' => ['must not be blank', 'must be at least 2 characters'],
        ]);

        self::assertSame([
            'email: must be a valid email address',
            'name: must not be blank',
            'name: must be at least 2 characters',
        ], $result->flatten());
    }

    public function testRequiredRuleOnArrayField(): void
    {
        // Sanity: Required rule also catches empty arrays and null
        $rule = new Required();
        $dto = ValidatedUserDto::fromArray(['email' => 'a@b.co', 'name' => 'xy']);

        self::assertSame('is required', $rule->check(null, 'x', $dto));
        self::assertSame('must not be empty', $rule->check([], 'x', $dto));
        self::assertSame('must not be blank', $rule->check('   ', 'x', $dto));
        self::assertNull($rule->check('ok', 'x', $dto));
        self::assertNull($rule->check([1], 'x', $dto));
    }
}
