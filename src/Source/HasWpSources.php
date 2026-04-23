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

    /**
     * @return static
     */
    public static function fromUser(int|\WP_User $user): DataObject
    {
        return UserSource::hydrate($user, static::class);
    }

    /**
     * @param list<int> $userIds
     * @return list<static>
     */
    public static function fromUsers(array $userIds): array
    {
        return UserSource::hydrateMany($userIds, static::class);
    }

    /**
     * @return static
     */
    public static function fromTerm(int|\WP_Term $term): DataObject
    {
        return TermSource::hydrate($term, static::class);
    }

    /**
     * @param list<int> $termIds
     * @return list<static>
     */
    public static function fromTerms(array $termIds): array
    {
        return TermSource::hydrateMany($termIds, static::class);
    }

    /**
     * Convenience shortcut for the no-guard case: hydrates straight from
     * the request parameters. If nonce or capability guards are needed,
     * call `RequestSource::from($request)` directly to build a guarded chain.
     *
     * @return static
     */
    public static function fromRequest(\WP_REST_Request $request): DataObject
    {
        return RequestSource::from($request)->into(static::class);
    }
}
