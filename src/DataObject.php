<?php

declare(strict_types=1);

namespace BetterData;

use BackedEnum;
use BetterData\Attribute\Encrypted;
use BetterData\Attribute\ListOf;
use BetterData\Encryption\EncryptionEngine;
use BetterData\Exception\MissingRequiredFieldException;
use BetterData\Exception\TypeCoercionException;
use BetterData\Internal\TypeCoercer;
use BetterData\Validation\BuiltInValidator;
use BetterData\Validation\ValidationEngineInterface;
use BetterData\Validation\ValidationResult;
use DateTimeInterface;
use JsonSerializable;
use ReflectionClass;
use ReflectionParameter;

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

            $args[$name] = self::coerceParameter($parameter, $data[$name]);
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
     * Remaining fields are preserved from the current instance. The
     * internal snapshot bypasses `toArray()`'s serialization pass (so
     * Secret / enum / DateTime values keep their rich types across
     * `with()`), then `fromArray()` re-runs type coercion on top of the
     * merged set.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): static
    {
        $reflection = new ReflectionClass($this);
        $snapshot = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $snapshot[$property->getName()] = $property->getValue($this);
        }

        return static::fromArray(array_replace($snapshot, $changes));
    }

    /**
     * Validate this DataObject and return a ValidationResult.
     *
     * Hydration stays throw-for-shape; business-rule validation is a
     * separate, explicit step. Pass a custom engine to swap out the
     * built-in attribute-reading validator (e.g. Symfony Validator adapter).
     */
    public function validate(?ValidationEngineInterface $engine = null): ValidationResult
    {
        return ($engine ?? new BuiltInValidator())->validate($this);
    }

    /**
     * Hydrate and validate in one step — the throw-early shortcut.
     *
     * Throws `TypeCoercionException` / `MissingRequiredFieldException`
     * from hydration as usual, then `ValidationException` if any rule
     * fails.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArrayValidated(array $data, ?ValidationEngineInterface $engine = null): static
    {
        $dto = static::fromArray($data);
        $dto->validate($engine)->throwIfInvalid();

        return $dto;
    }

    /**
     * Coerce a single incoming value for a constructor parameter.
     * Honours `#[ListOf($class)]` for list-of-object coercion — each
     * element is delegated to `$class::fromArray()` (or left alone if
     * it is already an instance of the target).
     */
    private static function coerceParameter(ReflectionParameter $parameter, mixed $value): mixed
    {
        // At-rest encryption: if the property carries #[Encrypted] and the
        // incoming raw value looks like a bd:v1: envelope, decrypt first
        // so downstream coercion sees the plaintext. Idempotent — the
        // AttributeDrivenHydrator meta path already decrypts, and this
        // check no-ops on a non-envelope value (such as a legacy plaintext
        // meta or a freshly-decrypted string).
        if (is_string($value)
            && $value !== ''
            && EncryptionEngine::looksEncrypted($value)
            && $parameter->getAttributes(Encrypted::class) !== []
        ) {
            $value = EncryptionEngine::decrypt($value, $parameter->getName());
        }

        $listOfAttr = $parameter->getAttributes(ListOf::class)[0] ?? null;
        if ($listOfAttr !== null && is_array($value)) {
            /** @var ListOf $listOf */
            $listOf = $listOfAttr->newInstance();
            $class = $listOf->class;
            $coerced = [];
            foreach ($value as $key => $element) {
                if ($element instanceof $class) {
                    $coerced[$key] = $element;
                    continue;
                }
                if (is_array($element) && method_exists($class, 'fromArray')) {
                    /** @var callable $factory */
                    $factory = [$class, 'fromArray'];
                    $coerced[$key] = $factory($element);
                    continue;
                }
                throw TypeCoercionException::for(
                    static::class,
                    $parameter->getName() . "[{$key}]",
                    $class,
                    $element,
                );
            }

            return $coerced;
        }

        return TypeCoercer::coerce(
            static::class,
            $parameter->getName(),
            $parameter->getType(),
            $value,
        );
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

        if ($value instanceof Secret) {
            // Leak-proof: the generic toArray path does NOT reveal secrets.
            // Callers who need the raw value must use Secret::reveal()
            // explicitly at the call site.
            return $value->jsonSerialize();
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        if (is_array($value)) {
            return array_map(self::serializeValue(...), $value);
        }

        return $value;
    }
}
