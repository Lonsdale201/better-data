<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class Regex implements Rule
{
    public function __construct(
        public string $pattern,
        public ?string $message = null,
    ) {
    }

    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || preg_match($this->pattern, $value) !== 1) {
            return $this->message ?? 'does not match the expected format';
        }

        return null;
    }
}
