<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\Encrypted;
use BetterData\DataObject;
use BetterData\Secret;

final readonly class OptionEncryptedDto extends DataObject
{
    public function __construct(
        public string $shopName,
        #[Encrypted]
        public Secret $apiKey,
        #[Encrypted]
        public ?string $plainSecretString = null,
    ) {
    }
}
