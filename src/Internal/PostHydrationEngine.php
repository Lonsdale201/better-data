<?php

declare(strict_types=1);

namespace BetterData\Internal;

use BetterData\Attribute\MetaKey;
use BetterData\Attribute\PostField;
use BetterData\DataObject;
use BetterData\Exception\DataObjectException;
use ReflectionClass;

/**
 * Pure, WP-independent engine that reads a post-like data structure and a
 * meta fetcher, resolves DataObject constructor parameters via MetaKey /
 * PostField attributes and WP_Post field conventions, and hands the
 * resulting array to the target DTO's fromArray().
 *
 * Keeping this layer pure means Post hydration logic is unit-testable
 * without a live WordPress runtime; the WP-aware adapter (`PostSource`)
 * is a thin wrapper.
 *
 * @internal Not part of the public API.
 */
final class PostHydrationEngine
{
    /**
     * Canonical WP_Post field list. `id` (lowercase) is treated as an alias
     * for `ID` when a property name matches it exactly.
     *
     * @var list<string>
     */
    private const POST_FIELDS = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'ping_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_content_filtered',
        'post_parent',
        'guid',
        'menu_order',
        'post_type',
        'post_mime_type',
        'comment_count',
    ];

    /**
     * @template T of DataObject
     * @param class-string<T>         $dtoClass
     * @param array<string, mixed>    $postFields Flat array of WP_Post field values (ID, post_title, ...)
     * @param callable(string): mixed $metaFetcher Fetches a single meta value for the post by key
     * @return T
     */
    public static function hydrate(
        string $dtoClass,
        array $postFields,
        callable $metaFetcher,
    ): DataObject {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $dtoClass::fromArray([]);
        }

        $data = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            $metaAttr = $parameter->getAttributes(MetaKey::class)[0] ?? null;
            if ($metaAttr !== null) {
                /** @var MetaKey $instance */
                $instance = $metaAttr->newInstance();
                $value = $metaFetcher($instance->key);
                if ($value === '' || $value === false) {
                    if ($parameter->isDefaultValueAvailable()) {
                        continue;
                    }

                    $type = $parameter->getType();
                    if ($type !== null && $type->allowsNull()) {
                        $data[$name] = null;
                        continue;
                    }
                }
                $data[$name] = $value;
                continue;
            }

            $postFieldAttr = $parameter->getAttributes(PostField::class)[0] ?? null;
            if ($postFieldAttr !== null) {
                /** @var PostField $instance */
                $instance = $postFieldAttr->newInstance();
                $data[$name] = $postFields[$instance->name] ?? null;
                continue;
            }

            $autoField = self::autoDetectPostField($name);
            if ($autoField !== null) {
                $data[$name] = $postFields[$autoField] ?? null;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            throw new class (sprintf(
                'Property "%s" on %s has no MetaKey or PostField attribute and does not match a known WP_Post field. Declare the source explicitly.',
                $name,
                $dtoClass,
            ), $dtoClass, $name) extends DataObjectException {
            };
        }

        return $dtoClass::fromArray($data);
    }

    private static function autoDetectPostField(string $propertyName): ?string
    {
        if ($propertyName === 'id') {
            return 'ID';
        }

        if (in_array($propertyName, self::POST_FIELDS, true)) {
            return $propertyName;
        }

        return null;
    }
}
