<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures\Middleware;

use BetterData\DataObject;
use BetterData\Hydration\HydrationContext;
use BetterData\Hydration\HydrationMiddleware;

final class OrderRecordingMiddleware implements HydrationMiddleware
{
    /**
     * @param list<string> $log
     */
    public function __construct(
        public array &$log,
        private readonly string $tag,
    ) {
    }

    public function process(HydrationContext $context, callable $next): DataObject
    {
        $this->log[] = 'before:' . $this->tag;
        $result = $next($context);
        $this->log[] = 'after:' . $this->tag;

        return $result;
    }
}
