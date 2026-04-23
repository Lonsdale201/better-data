<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Required implements Rule
{
    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return 'is required';
        }

        if (is_string($value) && trim($value) === '') {
            return 'must not be blank';
        }

        if (is_array($value) && $value === []) {
            return 'must not be empty';
        }

        return null;
    }
}
