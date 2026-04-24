<?php

declare(strict_types=1);

namespace BetterData\Internal;

use BackedEnum;
use BetterData\Attribute\MetaKey;
use BetterData\DataObject;
use BetterData\Secret;
use BetterData\Validation\Rule\Email;
use BetterData\Validation\Rule\Max;
use BetterData\Validation\Rule\MaxLength;
use BetterData\Validation\Rule\Min;
use BetterData\Validation\Rule\MinLength;
use BetterData\Validation\Rule\OneOf;
use BetterData\Validation\Rule\Regex;
use BetterData\Validation\Rule\Required;
use BetterData\Validation\Rule\Url;
use BetterData\Validation\Rule\Uuid;
use DateTimeInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Derives a JSON Schema from a DataObject class.
 *
 * Infers property types from PHP type declarations, then layers on
 * constraints from `#[Rule\...]` attributes:
 *
 *   Email     → format: email
 *   Url       → format: uri
 *   Uuid      → format: uuid
 *   MinLength → minLength
 *   MaxLength → maxLength
 *   Min       → minimum
 *   Max       → maximum
 *   Regex     → pattern
 *   OneOf     → enum
 *   Required  → field name added to top-level "required" list
 *
 * Output shape is `register_rest_route()`-compatible (feed it to `args`)
 * and also a valid JSON Schema draft-compatible document.
 *
 * @internal Not part of the public API — called from `MetaKeyRegistry`.
 */
final class RestSchemaBuilder
{
    /**
     * @param class-string<DataObject> $dtoClass
     * @return array<string, mixed>
     */
    public static function build(string $dtoClass): array
    {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        $properties = [];
        $required = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();
                $properties[$name] = self::buildProperty($parameter);

                if (self::isRequired($parameter)) {
                    $required[] = $name;
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Build the argument subset passed to `register_post_meta()`'s
     * `show_in_rest.schema` slot for a single meta-backed property.
     *
     * @return array<string, mixed>
     */
    public static function buildMetaSchema(ReflectionParameter $parameter, MetaKey $meta): array
    {
        $schema = self::buildProperty($parameter);
        if ($meta->type !== null) {
            $schema['type'] = $meta->type;
        }
        if ($meta->description !== null) {
            $schema['description'] = $meta->description;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildProperty(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $schema = self::inferBaseSchema($type);

        foreach ($parameter->getAttributes() as $attrReflection) {
            self::applyRuleAttribute($attrReflection, $schema);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function inferBaseSchema(?\ReflectionType $type): array
    {
        if (!$type instanceof ReflectionNamedType) {
            return ['type' => ['string', 'null']];
        }

        $name = $type->getName();
        $nullable = $type->allowsNull();

        if ($type->isBuiltin()) {
            $jsonType = match ($name) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                'array' => 'array',
                'string' => 'string',
                default => 'string',
            };

            $schema = $nullable
                ? ['type' => [$jsonType, 'null']]
                : ['type' => $jsonType];

            // Default `items` for unconstrained array fields. Must be a
            // PHP array (not stdClass) because WP core's
            // `rest_default_additional_properties_to_false()` recurses
            // into `items` and calls `(array) $schema['type']` on it —
            // a stdClass there triggers a fatal "Cannot use object of
            // type stdClass as array" during CPT REST route creation.
            //
            // Using `['type' => 'string']` as the conservative default:
            // most unconstrained WP meta arrays carry string lists
            // (tags, slugs, IDs). Consumers with richer element shapes
            // should supply an explicit hint (future `#[ArrayOf]` attr).
            if ($jsonType === 'array') {
                $schema['items'] = ['type' => 'string'];
            }

            return $schema;
        }

        if (is_subclass_of($name, BackedEnum::class)) {
            $values = [];
            foreach ($name::cases() as $case) {
                /** @var BackedEnum $case */
                $values[] = $case->value;
            }
            if ($nullable) {
                // JSON Schema validators reject `null` against an enum
                // list that doesn't include null, even when the `type`
                // allows it. Include null explicitly.
                $values[] = null;
            }
            $schema = ['enum' => $values];
            if (is_string($values[0] ?? null)) {
                $schema['type'] = $nullable ? ['string', 'null'] : 'string';
            } elseif (is_int($values[0] ?? null)) {
                $schema['type'] = $nullable ? ['integer', 'null'] : 'integer';
            }

            return $schema;
        }

        if ($name === DateTimeInterface::class || is_subclass_of($name, DateTimeInterface::class)) {
            return [
                'type' => $nullable ? ['string', 'null'] : 'string',
                'format' => 'date-time',
            ];
        }

        if ($name === Secret::class) {
            // OpenAPI 3 conventional marker for a secret-typed string.
            // Swagger UI / Redoc render a password input + never echo
            // the value back.
            return [
                'type' => $nullable ? ['string', 'null'] : 'string',
                'format' => 'password',
            ];
        }

        if (is_subclass_of($name, DataObject::class)) {
            /** @var class-string<DataObject> $name */
            $nested = self::build($name);
            if ($nullable) {
                $nested['type'] = [$nested['type'], 'null'];
            }

            return $nested;
        }

        return $nullable ? ['type' => ['string', 'null']] : ['type' => 'string'];
    }

    /**
     * @param ReflectionAttribute<object> $attrReflection
     * @param array<string, mixed>        $schema
     */
    private static function applyRuleAttribute(ReflectionAttribute $attrReflection, array &$schema): void
    {
        $name = $attrReflection->getName();

        switch ($name) {
            case Email::class:
                $schema['format'] = 'email';
                break;
            case Url::class:
                $schema['format'] = 'uri';
                break;
            case Uuid::class:
                $schema['format'] = 'uuid';
                break;
            case MinLength::class:
                /** @var MinLength $rule */
                $rule = $attrReflection->newInstance();
                $schema['minLength'] = $rule->min;
                break;
            case MaxLength::class:
                /** @var MaxLength $rule */
                $rule = $attrReflection->newInstance();
                $schema['maxLength'] = $rule->max;
                break;
            case Min::class:
                /** @var Min $rule */
                $rule = $attrReflection->newInstance();
                $schema['minimum'] = $rule->min;
                break;
            case Max::class:
                /** @var Max $rule */
                $rule = $attrReflection->newInstance();
                $schema['maximum'] = $rule->max;
                break;
            case Regex::class:
                /** @var Regex $rule */
                $rule = $attrReflection->newInstance();
                $schema['pattern'] = self::stripRegexDelimiters($rule->pattern);
                break;
            case OneOf::class:
                /** @var OneOf $rule */
                $rule = $attrReflection->newInstance();
                $schema['enum'] = array_values($rule->allowed);
                break;
        }
    }

    private static function isRequired(ReflectionParameter $parameter): bool
    {
        foreach ($parameter->getAttributes(Required::class) as $_) {
            return true;
        }
        if ($parameter->isDefaultValueAvailable()) {
            return false;
        }
        $type = $parameter->getType();
        if ($type !== null && $type->allowsNull()) {
            return false;
        }

        return true;
    }

    private static function stripRegexDelimiters(string $pattern): string
    {
        if (strlen($pattern) < 2) {
            return $pattern;
        }
        $delimiter = $pattern[0];
        $last = strrpos($pattern, $delimiter);
        if ($last === false || $last === 0) {
            return $pattern;
        }

        return substr($pattern, 1, $last - 1);
    }
}
