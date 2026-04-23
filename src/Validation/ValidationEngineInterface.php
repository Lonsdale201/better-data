<?php

declare(strict_types=1);

namespace BetterData\Validation;

use BetterData\DataObject;

/**
 * Contract for validating a DataObject.
 *
 * The library ships `BuiltInValidator` as the default — reads `Rule`
 * attributes off constructor parameters, recurses into nested DataObject
 * values. Consumers who prefer a different stack (Symfony Validator,
 * Respect, Laravel) write an adapter implementing this interface and
 * pass an instance into `DataObject::validate($engine)`.
 */
interface ValidationEngineInterface
{
    public function validate(DataObject $subject): ValidationResult;
}
