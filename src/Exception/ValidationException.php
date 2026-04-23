<?php

declare(strict_types=1);

namespace BetterData\Exception;

use BetterData\Validation\ValidationResult;
use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(
        public readonly ValidationResult $result,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : self::buildMessage($result));
    }

    public static function fromResult(ValidationResult $result): self
    {
        return new self($result);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->result->errors;
    }

    private static function buildMessage(ValidationResult $result): string
    {
        $flat = $result->flatten();

        return $flat === []
            ? 'Validation failed.'
            : 'Validation failed: ' . implode('; ', $flat);
    }
}
