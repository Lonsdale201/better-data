<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\MetaKey;
use BetterData\Attribute\UserField;
use BetterData\DataObject;

final readonly class UserBackedDto extends DataObject
{
    public function __construct(
        public int $id,
        #[UserField('user_login')]
        public string $login,
        #[UserField('user_email')]
        public string $email,
        #[MetaKey('billing_city')]
        public ?string $billingCity = null,
        #[MetaKey('loyalty_points')]
        public int $loyaltyPoints = 0,
    ) {
    }
}
