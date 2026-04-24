<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\Sensitive;
use BetterData\DataObject;

final readonly class SensitiveAccountDto extends DataObject
{
    public function __construct(
        public string $email,
        #[Sensitive]
        public string $apiKey,
        #[Sensitive]
        public ?string $recoveryToken = null,
    ) {
    }
}
