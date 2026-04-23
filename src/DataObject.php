<?php

declare(strict_types=1);

namespace BetterData;

use BackedEnum;
use BetterData\Exception\MissingRequiredFieldException;
use BetterData\Internal\TypeCoercer;
use DateTimeInterface;
use ReflectionClass;

/**
 * Abstract base class for typed, immutable data transfer objects.
 *
 * Subclasses should declare their shape via constructor promotion and
 * be marked `readonly` to guarantee immutability. Example:
 *
 *     final readonly class UserDto extends DataObject
 *     {
 *         public function __construct(
 *             public string $email,
 *             public ?string $name = null,
 *         ) {}
 *     }
 */
abstract readonly class DataObject
{
    /**
     * Hydrate a DataObject subclass from a string-keyed array.
     *
     * Keys match constructor parameter names. Missing keys fall back to
     * parameter defaults; missing required parameters throw.
     * Values are coerced to the declared parameter types.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var static */
            return $reflection->newInstance();
        }

        $args = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $data)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }

                $type = $parameter->getType();
                if ($type !== null && $type->allowsNull()) {
                    $args[$name] = null;
                    continue;
                }

                throw MissingRequiredFieldException::for(static::class, $name);
            }

            $args[$name] = TypeCoercer::coerce(
                static::class,
                $name,
                $parameter->getType(),
                $data[$name],
            );
        }

        /** @var static */
        return $reflection->newInstanceArgs($args);
    }

    /**
     * Serialize the DataObject into a plain string-keyed array.
     *
     * Nested DataObject values become arrays recursively. BackedEnum values
     * are unwrapped to their scalar value. DateTimeInterface values become
     * ISO 8601 strings. All other values are returned as-is.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reflection = new ReflectionClass($this);
        $out = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $out[$property->getName()] = self::serializeValue($property->getValue($this));
        }

        return $out;
    }

    /**
     * Return a new instance with the supplied fields replaced.
     *
     * Remaining fields are preserved from the current instance. The merged
     * data is re-hydrated via `fromArray()`, so type coercion runs again.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): static
    {
        return static::fromArray(array_replace($this->toArray(), $changes));
    }

    private static function serializeValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            return array_map(self::serializeValue(...), $value);
        }

        return $value;
    }
}
