<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;
use BetterData\Secret;

final readonly class SecretDto extends DataObject
{
    public function __construct(
        public string $clientId,
        public Secret $clientSecret,
        public ?Secret $refreshToken = null,
    ) {
    }
}
