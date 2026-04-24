<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Declares the element type of an `array`-typed DataObject property
 * so hydration and write-side projection can coerce list items into
 * the right shape instead of leaving them as raw arrays.
 *
 * Without `#[ListOf]`:
 *
 *     public array $lineItems,            // → array<array<string, mixed>>
 *
 * With `#[ListOf]`:
 *
 *     #[ListOf(LineItemDto::class)]
 *     public array $lineItems,            // → list<LineItemDto>
 *
 * Read path (`DataObject::fromArray`): each element that is an array
 * is coerced through `{target}::fromArray()`; elements that are
 * already instances of the target class pass through. Scalars,
 * nulls, and objects of other types raise a `TypeCoercionException`.
 *
 * Write path (`SinkProjection::prepareValue`): `DataObject` instances
 * inside the array get unwrapped to arrays so the storage backend
 * sees `array<array>` rather than serialized PHP objects (which
 * would tie the stored data to the class name).
 *
 * The target class need NOT extend `DataObject` — any class with a
 * compatible `::fromArray(array): static` static factory works (e.g.
 * the Dto's own facades). For non-DataObject targets, write-side
 * unwrap calls `->toArray()` if available, else stores the object
 * as-is (JsonSerializable fallback).
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class ListOf
{
    /**
     * @param class-string $class fully-qualified class name of each element
     */
    public function __construct(public string $class)
    {
    }
}
