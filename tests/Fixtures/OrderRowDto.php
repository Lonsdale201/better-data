<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\Column;
use BetterData\DataObject;
use DateTimeImmutable;

final readonly class OrderRowDto extends DataObject
{
    public function __construct(
        public int $id,
        #[Column('user_id')]
        public int $userId,
        #[Column('order_total')]
        public float $total,
        public string $status,
        #[Column('created_at')]
        public DateTimeImmutable $createdAt,
    ) {
    }
}
