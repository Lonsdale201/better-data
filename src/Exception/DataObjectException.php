<?php

declare(strict_types=1);

namespace BetterData\Exception;

use RuntimeException;

abstract class DataObjectException extends RuntimeException
{
    public function __construct(
        string $message,
        protected readonly string $dataObjectClass,
        protected readonly ?string $fieldName = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getDataObjectClass(): string
    {
        return $this->dataObjectClass;
    }

    public function getFieldName(): ?string
    {
        return $this->fieldName;
    }
}
