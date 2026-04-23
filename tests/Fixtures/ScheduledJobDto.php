<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\Column;
use BetterData\Attribute\DateFormat;
use BetterData\DataObject;
use DateTimeImmutable;

final readonly class ScheduledJobDto extends DataObject
{
    public function __construct(
        public int $id,
        #[Column('job_name')]
        public string $name,
        #[Column('scheduled_at')]
        public DateTimeImmutable $scheduledAt,
        #[Column('delivery_date'), DateFormat('Y-m-d')]
        public DateTimeImmutable $deliveryDate,
        #[Column('epoch'), DateFormat('U')]
        public DateTimeImmutable $epoch,
        public string $status = 'pending',
    ) {
    }
}
