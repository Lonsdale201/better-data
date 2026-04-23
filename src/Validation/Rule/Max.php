<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Max implements Rule
{
    public function __construct(public int|float $max)
    {
    }

    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_float($value)) {
            return 'must be numeric';
        }

        if ($value > $this->max) {
            return sprintf('must not be greater than %s', $this->max);
        }

        return null;
    }
}
