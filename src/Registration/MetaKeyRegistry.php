<?php

declare(strict_types=1);

namespace BetterData\Registration;

use BetterData\Attribute\MetaKey;
use BetterData\DataObject;
use BetterData\Internal\RestSchemaBuilder;
use BetterData\Internal\TypeCoercer;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Registers the meta keys declared on a DataObject with WordPress.
 *
 * Walks every constructor parameter carrying `#[MetaKey]` and calls
 * `register_post_meta` / `register_user_meta` / `register_term_meta`
 * with shape info (type, single, show_in_rest, default, description,
 * sanitize_callback, auth_callback) derived from the attribute.
 *
 * **Scope limit (intentional):** the registry does NOT register post
 * types, taxonomies, REST routes, or REST endpoints. Those are
 * app-level decisions that a data-layer library has no business making.
 * Register your post type in your plugin's bootstrap; register meta
 * keys here.
 *
 * Typical wiring (plugin bootstrap):
 *
 *     add_action('init', static function (): void {
 *         \\BetterData\\Registration\\MetaKeyRegistry::register(
 *             ProductDto::class,
 *             objectType: 'post',
 *             subtype: 'product',
 *         );
 *     });
 *
 * For sourcing the REST schema (e.g. to feed `register_rest_route`'s
 * `args`, or an OpenAPI generator), use `toRestSchema()` — it's a pure
 * projection, no WP side-effects.
 */
final class MetaKeyRegistry
{
    /**
     * @var list<string>
     */
    private const VALID_OBJECT_TYPES = ['post', 'user', 'term', 'comment'];

    /**
     * Register every `#[MetaKey]`-annotated property of the given DTO.
     *
     * Returns the list of meta keys that were registered.
     *
     * @param class-string<DataObject>                 $dtoClass
     * @param 'post'|'user'|'term'|'comment'           $objectType
     * @return list<string>
     */
    public static function register(
        string $dtoClass,
        string $objectType = 'post',
        string $subtype = '',
    ): array {
        if (!in_array($objectType, self::VALID_OBJECT_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid objectType "%s". Expected one of: %s.',
                $objectType,
                implode(', ', self::VALID_OBJECT_TYPES),
            ));
        }

        if (!\function_exists('register_meta')) {
            return [];
        }

        $registered = [];
        foreach (self::collectMetaParameters($dtoClass) as [$parameter, $meta]) {
            $args = self::buildRegisterArgs($parameter, $meta, $objectType, $subtype);
            \register_meta($objectType, $meta->key, $args);
            $registered[] = $meta->key;
        }

        return $registered;
    }

    /**
     * Build a JSON Schema-compatible object describing the whole DTO.
     * Suitable for `register_rest_route($route, ['args' => ...])` or
     * external OpenAPI tooling.
     *
     * @param class-string<DataObject> $dtoClass
     * @return array<string, mixed>
     */
    public static function toRestSchema(string $dtoClass): array
    {
        return RestSchemaBuilder::build($dtoClass);
    }

    /**
     * @param class-string<DataObject> $dtoClass
     * @return iterable<array{0: ReflectionParameter, 1: MetaKey}>
     */
    private static function collectMetaParameters(string $dtoClass): iterable
    {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        foreach ($constructor->getParameters() as $parameter) {
            $attr = $parameter->getAttributes(MetaKey::class)[0] ?? null;
            if ($attr === null) {
                continue;
            }
            /** @var MetaKey $meta */
            $meta = $attr->newInstance();
            yield [$parameter, $meta];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildRegisterArgs(
        ReflectionParameter $parameter,
        MetaKey $meta,
        string $objectType,
        string $subtype,
    ): array {
        $args = [
            'single' => $meta->single,
            'type' => $meta->type ?? self::inferType($parameter),
        ];

        if ($meta->description !== null) {
            $args['description'] = $meta->description;
        }

        if ($meta->default !== null) {
            $args['default'] = $meta->default;
        }

        if ($meta->showInRest) {
            $args['show_in_rest'] = [
                'schema' => RestSchemaBuilder::buildMetaSchema($parameter, $meta),
            ];
        }

        if ($meta->autoSanitize) {
            $args['sanitize_callback'] = self::makeSanitizeCallback($parameter);
        }

        if ($meta->authCapability !== null) {
            $args['auth_callback'] = self::makeAuthCallback($meta->authCapability);
        }

        if ($objectType === 'post' && $subtype !== '') {
            $args['object_subtype'] = $subtype;
        } elseif ($objectType === 'term' && $subtype !== '') {
            $args['object_subtype'] = $subtype;
        }

        return $args;
    }

    private static function inferType(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            return 'string';
        }

        return match ($type->getName()) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            'string' => 'string',
            default => 'string',
        };
    }

    private static function makeSanitizeCallback(ReflectionParameter $parameter): \Closure
    {
        $declaringClass = $parameter->getDeclaringClass()?->getName() ?? 'unknown';
        $fieldName = $parameter->getName();
        $type = $parameter->getType();

        return static function (mixed $value) use ($declaringClass, $fieldName, $type): mixed {
            try {
                return TypeCoercer::coerce($declaringClass, $fieldName, $type, $value);
            } catch (\Throwable) {
                return $value;
            }
        };
    }

    private static function makeAuthCallback(string $capability): \Closure
    {
        return static function () use ($capability): bool {
            if (!\function_exists('current_user_can')) {
                return false;
            }

            return (bool) \current_user_can($capability);
        };
    }
}
