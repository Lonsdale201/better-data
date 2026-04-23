<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures\Middleware;

use BetterData\DataObject;
use BetterData\Hydration\HydrationContext;
use BetterData\Hydration\HydrationMiddleware;

final class ShortCircuitMiddleware implements HydrationMiddleware
{
    public function __construct(private readonly DataObject $precomputed)
    {
    }

    public function process(HydrationContext $context, callable $next): DataObject
    {
        return $this->precomputed;
    }
}
