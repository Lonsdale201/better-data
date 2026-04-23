<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use Attribute;
use BetterData\DataObject;
use BetterData\Validation\Rule;

/**
 * Value must be one of a fixed set of scalars.
 *
 * Named `OneOf` (rather than `Enum`) to avoid collision with PHP's
 * backed enums, which the type system already handles at coercion time.
 * Use `OneOf` when the field is a scalar that must be constrained to a
 * known vocabulary without going the full PHP enum route.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class OneOf implements Rule
{
    /**
     * @param list<string|int|float|bool> $allowed
     */
    public function __construct(public array $allowed)
    {
    }

    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!in_array($value, $this->allowed, true)) {
            return sprintf('must be one of: %s', implode(', ', array_map(
                static fn ($v): string => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
                $this->allowed,
            )));
        }

        return null;
    }
}
