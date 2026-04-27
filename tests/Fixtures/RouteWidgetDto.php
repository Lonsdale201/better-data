<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\DataObject;

final readonly class RouteWidgetDto extends DataObject
{
    public function __construct(
        public int $id = 0,
        public string $name = '',
        public bool $active = true,
    ) {
    }
}
