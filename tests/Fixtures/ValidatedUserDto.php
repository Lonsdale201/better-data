<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;
use BetterData\Validation\Rule;

final readonly class ValidatedUserDto extends DataObject
{
    public function __construct(
        #[Rule\Required, Rule\Email]
        public string $email,
        #[Rule\Required, Rule\MinLength(2), Rule\MaxLength(50)]
        public string $name,
        #[Rule\Min(0), Rule\Max(150)]
        public int $age = 0,
        #[Rule\OneOf(['admin', 'editor', 'subscriber'])]
        public string $role = 'subscriber',
        #[Rule\Url]
        public ?string $website = null,
        #[Rule\Uuid]
        public ?string $externalId = null,
        #[Rule\Regex('/^\+[0-9]{7,}$/', 'must be an E.164 phone number')]
        public ?string $phone = null,
    ) {
    }
}
