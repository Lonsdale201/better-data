<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class CapabilityCheckFailedException extends RequestGuardException
{
    public function __construct(
        public readonly string $capability,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf('Current user lacks the "%s" capability.', $capability),
        );
    }

    public static function for(string $capability): self
    {
        return new self($capability);
    }
}
