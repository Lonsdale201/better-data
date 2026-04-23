<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Email implements Rule
{
    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return 'must be a valid email address';
        }

        return null;
    }
}
