<?php

declare(strict_types=1);

namespace BetterData\Exception;

/**
 * Thrown when a route-owned field (e.g. a URL path segment) is also
 * supplied by the request body or query string, indicating a potential
 * client-driven override of a server-authoritative value.
 */
final class RequestParamCollisionException extends RequestGuardException
{
    /**
     * @param list<string> $collidingFields
     */
    public static function forFields(array $collidingFields): self
    {
        return new self(sprintf(
            'Route-owned field(s) appeared in client-controlled payload: %s',
            implode(', ', $collidingFields),
        ));
    }
}
