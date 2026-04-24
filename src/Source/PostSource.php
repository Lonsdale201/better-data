<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\Attribute\PostField;
use BetterData\DataObject;
use BetterData\Exception\PostNotFoundException;
use BetterData\Internal\AttributeDrivenHydrator;

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

        $siteTz = \function_exists('wp_timezone_string') ? \wp_timezone_string() : 'UTC';
        $fieldTimezones = [
            'post_date' => $siteTz,
            'post_modified' => $siteTz,
            'post_date_gmt' => 'UTC',
            'post_modified_gmt' => 'UTC',
        ];

        return AttributeDrivenHydrator::hydrate(
            $dtoClass,
            $postFields,
            self::POST_FIELDS,
            PostField::class,
            static function (string $key) use ($postId): mixed {
                if (\function_exists('metadata_exists')
                    && !\metadata_exists('post', $postId, $key)) {
                    return null;
                }

                return \get_post_meta($postId, $key, true);
            },
            ['id' => 'ID'],
            $fieldTimezones,
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

        // Prewarm both post and meta caches in one go. Without the post
        // prime, each per-id get_post() round-trips to the DB — N+1 when
        // caches are cold. _prime_post_caches pulls every post in one
        // SELECT, and update_meta_cache pulls every meta row in a second.
        if (\function_exists('_prime_post_caches')) {
            \_prime_post_caches($postIds, true, true);
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
