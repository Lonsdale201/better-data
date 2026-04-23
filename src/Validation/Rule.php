<?php

declare(strict_types=1);

namespace BetterData\Validation;

use BetterData\DataObject;

/**
 * A single validation rule applied to a field on a DataObject.
 *
 * Implementations are typically attribute classes so they can be attached
 * directly to promoted constructor parameters:
 *
 *     #[Rule\Required, Rule\Email]
 *     public string $email,
 *
 * `check()` returns `null` if the value passes, or a short error message
 * if it fails. The full DataObject `$subject` is provided for cross-field
 * rules (e.g. "password must match passwordConfirmation").
 *
 * Convention: rules other than `Required` treat `null` as "skip" so
 * nullable/optional fields can carry rules without false positives.
 */
interface Rule
{
    public function check(mixed $value, string $fieldName, DataObject $subject): ?string;
}
