<?php

declare(strict_types=1);

namespace BetterData\Exception;

use RuntimeException;

/**
 * Base class for guards that reject a REST request before hydration runs.
 * Distinct from `DataObjectException` because no DTO class is in scope yet
 * (the request may never reach a DTO if a guard fails).
 */
abstract class RequestGuardException extends RuntimeException
{
}
