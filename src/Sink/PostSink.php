<?php

declare(strict_types=1);

namespace BetterData\Sink;

use BetterData\Attribute\PostField;
use BetterData\DataObject;
use BetterData\Exception\MissingIdentifierException;
use BetterData\Internal\SinkProjection;

/**
 * Writes DataObjects back to the WordPress posts store.
 *
 * Two usage modes:
 *
 *  - **Projection** (`toArgs` / `toMeta`): returns plain arrays shaped
 *    for `wp_insert_post` / `wp_update_post` and for the meta write
 *    loop. The caller issues the database calls. Safer, unit-test
 *    friendly, and the recommended mode when you need control.
 *
 *  - **Convenience** (`insert` / `update` / `save`): commits everything
 *    in one call. Same underlying projection, with the WP function
 *    calls performed internally. `save()` routes to insert vs update
 *    based on whether the DTO carries a positive `id`.
 *
 * Partial updates are supported via the `$only` parameter: pass a list
 * of DTO property names to restrict the write set. Anything outside the
 * list is left untouched. Default (`$only = null`) writes the complete
 * set the DTO declares.
 *
 * Meta write convention:
 *  - Non-null DTO value → `update_post_meta($id, $key, $value)`
 *  - Null DTO value     → `delete_post_meta($id, $key)`
 *
 * Slashing policy:
 *  - Convenience methods (`insert` / `update` / `save`) pass values
 *    through `wp_slash()` before handing them to
 *    `wp_insert_post` / `wp_update_post` / `update_post_meta` /
 *    `meta_input`, because the core WP write pipeline calls
 *    `wp_unslash()` on inbound data. Without slashing, a value
 *    containing `\"` would round-trip to `"`.
 *  - Projection methods (`toArgs` / `toMeta`) return RAW values — the
 *    caller that issues its own WP calls is responsible for slashing.
 *
 * Update requires the DTO to carry a post identifier (`id` property or
 * anything attribute-mapped to `ID`). Without it, `MissingIdentifierException`.
 */
final class PostSink
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
     * @var list<string>
     */
    private const GMT_FIELDS = ['post_date_gmt', 'post_modified_gmt'];

    /**
     * Build the wp_insert_post / wp_update_post argument array (including
     * `meta_input` for `wp_insert_post`, empty when updating).
     *
     * @param list<string>|null $only property-name whitelist
     * @return array<string, mixed>
     */
    public static function toArgs(DataObject $dto, ?array $only = null): array
    {
        $projection = self::project($dto, $only);

        $args = $projection['system'];
        if ($projection['meta'] !== []) {
            $args['meta_input'] = $projection['meta'];
        }

        return $args;
    }

    /**
     * Return the meta write set as two lists: `write` (key → value)
     * and `delete` (list of keys).
     *
     * @param list<string>|null $only
     * @return array{write: array<string, mixed>, delete: list<string>}
     */
    public static function toMeta(DataObject $dto, ?array $only = null): array
    {
        $projection = self::project($dto, $only);

        return [
            'write' => $projection['meta'],
            'delete' => $projection['metaToDelete'],
        ];
    }

    /**
     * Insert a new post. If the DTO happens to carry an `id`/`ID`, it's
     * stripped — use `update()` to modify existing posts.
     *
     * Returns the newly-created post ID. Throws a `\RuntimeException`
     * propagated from WP on failure.
     *
     * @param list<string>|null $only
     */
    public static function insert(DataObject $dto, ?array $only = null): int
    {
        $args = self::toArgs($dto, $only);
        unset($args['ID']);

        $meta = self::toMeta($dto, $only);
        if ($meta['write'] !== []) {
            $args['meta_input'] = $meta['write'];
        }

        $args = \wp_slash($args);

        $result = \wp_insert_post($args, true);

        if (\is_wp_error($result)) {
            throw new \RuntimeException(
                'wp_insert_post failed: ' . $result->get_error_message(),
            );
        }

        return (int) $result;
    }

    /**
     * Update an existing post. The ID comes from `$postId` if provided,
     * otherwise from the DTO's identifier field (`id` / `ID`).
     *
     * Delete-meta step runs after the post update so meta mutations are
     * visible in subsequent reads.
     *
     * @param list<string>|null $only
     * @return int post ID that was updated
     */
    public static function update(DataObject $dto, ?int $postId = null, ?array $only = null): int
    {
        $postId ??= self::identifierOf($dto);
        if ($postId <= 0) {
            throw MissingIdentifierException::forUpdate($dto::class, 'id');
        }

        $args = self::toArgs($dto, $only);
        unset($args['meta_input']);
        $args['ID'] = $postId;

        $result = \wp_update_post(\wp_slash($args), true);
        if (\is_wp_error($result)) {
            throw new \RuntimeException(
                'wp_update_post failed: ' . $result->get_error_message(),
            );
        }

        $meta = self::toMeta($dto, $only);
        foreach ($meta['write'] as $key => $value) {
            \update_post_meta($postId, $key, \wp_slash($value));
        }
        foreach ($meta['delete'] as $key) {
            \delete_post_meta($postId, $key);
        }

        return $postId;
    }

    /**
     * Route to insert or update based on whether the DTO declares a
     * positive identifier.
     *
     * @param list<string>|null $only
     * @return int post ID
     */
    public static function save(DataObject $dto, ?array $only = null): int
    {
        $id = self::tryIdentifierOf($dto);

        return $id > 0 ? self::update($dto, $id, $only) : self::insert($dto, $only);
    }

    /**
     * @param list<string>|null $only
     * @return array{system: array<string, mixed>, meta: array<string, mixed>, metaToDelete: list<string>}
     */
    private static function project(DataObject $dto, ?array $only): array
    {
        return SinkProjection::project(
            $dto,
            PostField::class,
            self::POST_FIELDS,
            propertyAliases: ['id' => 'ID'],
            only: $only,
            gmtSystemFields: self::GMT_FIELDS,
        );
    }

    private static function identifierOf(DataObject $dto): int
    {
        $id = self::tryIdentifierOf($dto);
        if ($id <= 0) {
            throw MissingIdentifierException::forUpdate($dto::class, 'id');
        }

        return $id;
    }

    private static function tryIdentifierOf(DataObject $dto): int
    {
        $args = self::toArgs($dto);

        return isset($args['ID']) ? (int) $args['ID'] : 0;
    }
}
