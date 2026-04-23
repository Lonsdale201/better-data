<?php

declare(strict_types=1);

namespace BetterData\Validation;

use BetterData\Exception\ValidationException;

/**
 * Immutable value object holding the outcome of a validation pass.
 *
 * Returned by `DataObject::validate()` / `ValidationEngineInterface::validate()`.
 * Consumers choose whether to branch on `isValid()` / `errors` or to call
 * `throwIfInvalid()` for the throw-early idiom.
 */
final readonly class ValidationResult
{
    /**
     * @param array<string, list<string>> $errors field path → list of error messages
     */
    public function __construct(public array $errors = [])
    {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<string>
     */
    public function errorsFor(string $fieldPath): array
    {
        return $this->errors[$fieldPath] ?? [];
    }

    public function firstError(string $fieldPath): ?string
    {
        return $this->errors[$fieldPath][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function flatten(): array
    {
        $flat = [];
        foreach ($this->errors as $field => $messages) {
            foreach ($messages as $message) {
                $flat[] = $field . ': ' . $message;
            }
        }

        return $flat;
    }

    public function throwIfInvalid(): void
    {
        if ($this->hasErrors()) {
            throw ValidationException::fromResult($this);
        }
    }
}
