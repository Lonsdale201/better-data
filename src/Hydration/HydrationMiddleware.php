<?php

declare(strict_types=1);

namespace BetterData\Hydration;

use BetterData\DataObject;

/**
 * Middleware participating in the hydration pipeline.
 *
 * Implementations inspect or derive a new `HydrationContext`, then call
 * `$next($context)` to continue the pipeline. Short-circuiting — returning
 * a DataObject without calling `$next` — is permitted.
 *
 * Middleware MUST return a DataObject instance; either by calling `$next`
 * (the common case) or by constructing one itself.
 */
interface HydrationMiddleware
{
    /**
     * @param callable(HydrationContext): DataObject $next
     */
    public function process(HydrationContext $context, callable $next): DataObject;
}
