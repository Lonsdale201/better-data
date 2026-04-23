<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;

final readonly class UserDto extends DataObject
{
    public function __construct(
        public string $email,
        public ?string $name = null,
        public int $age = 0,
        public bool $active = true,
    ) {
    }
}
