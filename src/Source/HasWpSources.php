<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\DataObject;

/**
 * Optional convenience trait: exposes `::fromPost / fromPosts / fromOption
 * / fromRow / fromRows` as one-liners on the DTO class itself.
 *
 * The static source classes (`PostSource`, `OptionSource`, `RowSource`)
 * remain the canonical API; this trait is purely syntactic sugar for
 * callers who prefer `ProductDto::fromPost($id)` over
 * `PostSource::hydrate($id, ProductDto::class)`.
 *
 * ```php
 * final readonly class ProductDto extends DataObject
 * {
 *     use HasWpSources;
 *     // ...
 * }
 *
 * $product = ProductDto::fromPost($postId);
 * ```
 */
trait HasWpSources
{
    /**
     * @return static
     */
    public static function fromPost(int|\WP_Post $post): DataObject
    {
        return PostSource::hydrate($post, static::class);
    }

    /**
     * @param list<int> $postIds
     * @return list<static>
     */
    public static function fromPosts(array $postIds): array
    {
        return PostSource::hydrateMany($postIds, static::class);
    }

    /**
     * @param array<string, mixed> $default
     * @return static
     */
    public static function fromOption(string $option, array $default = []): DataObject
    {
        return OptionSource::hydrate($option, static::class, $default);
    }

    /**
     * @param array<string, mixed>|object $row
     * @return static
     */
    public static function fromRow(array|object $row): DataObject
    {
        return RowSource::hydrate($row, static::class);
    }

    /**
     * @param iterable<array<string, mixed>|object> $rows
     * @return list<static>
     */
    public static function fromRows(iterable $rows): array
    {
        return RowSource::hydrateMany($rows, static::class);
    }
}
