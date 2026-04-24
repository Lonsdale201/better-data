<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;

final readonly class NullableEnumDto extends DataObject
{
    public function __construct(
        public ?UserRole $role = null,
    ) {
    }
}
