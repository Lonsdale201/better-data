<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures\Middleware;

use BetterData\DataObject;
use BetterData\Hydration\HydrationContext;
use BetterData\Hydration\HydrationMiddleware;

final class InjectDefaultsMiddleware implements HydrationMiddleware
{
    /**
     * @param array<string, mixed> $defaults
     */
    public function __construct(private readonly array $defaults)
    {
    }

    public function process(HydrationContext $context, callable $next): DataObject
    {
        $data = $context->data;

        foreach ($this->defaults as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        return $next($context->withData($data));
    }
}
