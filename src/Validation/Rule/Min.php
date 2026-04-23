<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Min implements Rule
{
    public function __construct(public int|float $min)
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

        if ($value < $this->min) {
            return sprintf('must be at least %s', $this->min);
        }

        return null;
    }
}
