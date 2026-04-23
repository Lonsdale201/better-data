<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;
use BetterData\Validation\Rule;

final readonly class ValidatedOrderDto extends DataObject
{
    public function __construct(
        #[Rule\Required]
        public string $orderNumber,
        #[Rule\Min(0.01)]
        public float $total,
        public ValidatedUserDto $customer,
    ) {
    }
}
