<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\DataObject;
use BetterData\Exception\PostNotFoundException;
use BetterData\Internal\PostHydrationEngine;

/**
 * Hydrates DataObjects from WordPress posts and their meta.
 *
 * - Single hydration (`hydrate`) reads only the meta keys declared via
 *   `#[MetaKey]` on the target DTO. Thanks to WP's per-post meta cache,
 *   N declared keys cost 1 DB query (the first call primes the cache).
 *
 * - Bulk hydration (`hydrateMany`) calls `update_meta_cache('post', $ids)`
 *   first, so N posts still cost 1 post query + 1 meta query total.
 *
 * The mapping rules:
 *
 * - Property has `#[MetaKey('x')]`  → value from `get_post_meta($id, 'x', true)`
 * - Property has `#[PostField('x')]` → value from `$post->x`
 * - Property name matches a known WP_Post field (or `id` → `ID`) → auto
 * - Otherwise, an explicit attribute is required (the engine throws)
 */
final class PostSource
{
    /**
     * @template T of DataObject
     * @param int|\WP_Post    $post
     * @param class-string<T> $dtoClass
     * @return T
     */
    public static function hydrate(int|\WP_Post $post, string $dtoClass): DataObject
    {
        $wpPost = $post instanceof \WP_Post ? $post : \get_post($post);
        if (!$wpPost instanceof \WP_Post) {
            throw PostNotFoundException::forId($dtoClass, is_int($post) ? $post : 0);
        }

        /** @var array<string, mixed> $postFields */
        $postFields = (array) $wpPost;
        $postId = (int) $wpPost->ID;

        return PostHydrationEngine::hydrate(
            $dtoClass,
            $postFields,
            static fn (string $key): mixed => \get_post_meta($postId, $key, true),
        );
    }

    /**
     * @template T of DataObject
     * @param list<int>       $postIds
     * @param class-string<T> $dtoClass
     * @return list<T>
     */
    public static function hydrateMany(array $postIds, string $dtoClass): array
    {
        if ($postIds === []) {
            return [];
        }

        if (\function_exists('update_meta_cache')) {
            \update_meta_cache('post', $postIds);
        }

        $out = [];
        foreach ($postIds as $id) {
            $wpPost = \get_post($id);
            if ($wpPost instanceof \WP_Post) {
                $out[] = self::hydrate($wpPost, $dtoClass);
            }
        }

        return $out;
    }
}
