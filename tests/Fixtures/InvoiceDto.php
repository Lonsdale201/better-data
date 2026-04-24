<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\ListOf;
use BetterData\DataObject;

final readonly class InvoiceDto extends DataObject
{
    /**
     * @param list<AddressDto> $billingAddresses
     */
    public function __construct(
        public string $number,
        #[ListOf(AddressDto::class)]
        public array $billingAddresses = [],
    ) {
    }
}
