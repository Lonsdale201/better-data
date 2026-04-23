<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Uuid implements Rule
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return 'must be a valid UUID';
        }

        return null;
    }
}
