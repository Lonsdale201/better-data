<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures\Middleware;

use BetterData\DataObject;
use BetterData\Hydration\HydrationContext;
use BetterData\Hydration\HydrationMiddleware;

final class RenameFieldsMiddleware implements HydrationMiddleware
{
    /**
     * @param array<string, string> $map source key => target key
     */
    public function __construct(private readonly array $map)
    {
    }

    public function process(HydrationContext $context, callable $next): DataObject
    {
        $data = $context->data;

        foreach ($this->map as $from => $to) {
            if (array_key_exists($from, $data)) {
                $data[$to] = $data[$from];
                unset($data[$from]);
            }
        }

        return $next($context->withData($data));
    }
}
