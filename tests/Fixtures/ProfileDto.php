<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;
use DateTimeImmutable;

final readonly class ProfileDto extends DataObject
{
    public function __construct(
        public string $username,
        public UserRole $role,
        public AddressDto $address,
        public DateTimeImmutable $joinedAt,
        public ?float $balance = null,
    ) {
    }
}
