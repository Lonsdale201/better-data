<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;

final readonly class AddressDto extends DataObject
{
    public function __construct(
        public string $city,
        public string $country,
        public ?string $zip = null,
    ) {
    }
}
