<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class MaxLength implements Rule
{
    public function __construct(public int $max)
    {
    }

    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length > $this->max) {
                return sprintf('must not exceed %d characters', $this->max);
            }

            return null;
        }

        if (is_array($value)) {
            if (count($value) > $this->max) {
                return sprintf('must not contain more than %d items', $this->max);
            }

            return null;
        }

        return 'must be a string or array';
    }
}
