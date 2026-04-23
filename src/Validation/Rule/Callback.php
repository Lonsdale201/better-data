<?php

declare(strict_types=1);

namespace BetterData\Validation\Rule;

use BetterData\DataObject;
use BetterData\Validation\Rule;

/**
 * Escape hatch for arbitrary validation logic.
 *
 * Not an attribute — attributes can only carry simple scalars/arrays,
 * so closures are out. Wire a Callback rule through a custom engine or
 * a domain-specific `rules()` layer if you build one on top. The library
 * provides the class as a convenience for implementation use.
 *
 * ```php
 * new Callback(
 *     static fn (mixed $value, string $field, DataObject $subject): ?string =>
 *         $value === $subject->passwordConfirmation ? null : 'must match password confirmation',
 * );
 * ```
 */
final readonly class Callback implements Rule
{
    /**
     * @param \Closure(mixed, string, DataObject): ?string $check
     */
    public function __construct(private \Closure $check)
    {
    }

    public function check(mixed $value, string $fieldName, DataObject $subject): ?string
    {
        return ($this->check)($value, $fieldName, $subject);
    }
}
