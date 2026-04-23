<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class NonceVerificationFailedException extends RequestGuardException
{
    public function __construct(
        public readonly string $action,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf('Nonce verification failed for action "%s".', $action),
        );
    }

    public static function forAction(string $action): self
    {
        return new self($action);
    }
}
