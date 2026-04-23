<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class MinLength implements Rule
{
    public function __construct(public int $min)
    {
    }

    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length < $this->min) {
                return sprintf('must be at least %d characters', $this->min);
            }

            return null;
        }

        if (is_array($value)) {
            if (count($value) < $this->min) {
                return sprintf('must contain at least %d items', $this->min);
            }

            return null;
        }

        return 'must be a string or array';
    }
}
