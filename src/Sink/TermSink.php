<?php

declare(strict_types=1);

namespace BetterData\Sink;

use BetterData\Attribute\TermField;
use BetterData\DataObject;
use BetterData\Exception\MissingIdentifierException;
use BetterData\Internal\SinkProjection;

/**
 * Writes DataObjects back to the WordPress terms store.
 *
 * WP's term API splits the write: `wp_insert_term(name, taxonomy, args)`
 * and `wp_update_term(term_id, taxonomy, args)` take `name` / `taxonomy`
 * as dedicated parameters, not as part of the args array. The sink
 * extracts them from the projection before calling WP.
 *
 * `term_taxonomy_id`, `parent`, and `count` are taxonomy-internal —
 * WP computes them. If the DTO declares them, they're ignored at write
 * time (WP docs: "`term_taxonomy_id` is read-only").
 *
 * Slashing policy: see PostSink. Convenience methods slash via
 * `wp_slash()`; projections stay raw.
 */
final class TermSink
{
    /**
     * @var list<string>
     */
    private const TERM_FIELDS = [
        'term_id',
        'name',
        'slug',
        'term_group',
        'term_taxonomy_id',
        'taxonomy',
        'description',
        'parent',
        'count',
    ];

    /**
     * @var list<string>
     */
    private const WRITE_IGNORED = ['term_taxonomy_id', 'count'];

    /**
     * @param list<string>|null $only
     * @return array{term_id: int|null, name: string|null, taxonomy: string|null, args: array<string, mixed>, meta: array{write: array<string, mixed>, delete: list<string>}}
     */
    public static function toArgs(DataObject $dto, ?array $only = null): array
    {
        $projection = self::project($dto, $only);
        $system = $projection['system'];

        $termId = isset($system['term_id']) ? (int) $system['term_id'] : null;
        $name = isset($system['name']) && is_string($system['name']) ? $system['name'] : null;
        $taxonomy = isset($system['taxonomy']) && is_string($system['taxonomy']) ? $system['taxonomy'] : null;

        unset($system['term_id'], $system['name'], $system['taxonomy']);

        return [
            'term_id' => $termId,
            'name' => $name,
            'taxonomy' => $taxonomy,
            'args' => $system,
            'meta' => [
                'write' => $projection['meta'],
                'delete' => $projection['metaToDelete'],
            ],
        ];
    }

    /**
     * @param list<string>|null $only
     */
    public static function insert(
        DataObject $dto,
        ?array $only = null,
        bool $strict = false,
        bool $skipNullDeletes = false,
    ): int {
        if ($strict) {
            self::project($dto, $only, true, $skipNullDeletes);
        }
        $parts = self::toArgs($dto, $only);

        if ($parts['name'] === null || $parts['taxonomy'] === null) {
            throw new \RuntimeException(sprintf(
                'Cannot insert term from %s: both `name` and `taxonomy` are required.',
                $dto::class,
            ));
        }

        $result = \wp_insert_term(
            \wp_slash($parts['name']),
            $parts['taxonomy'],
            \wp_slash($parts['args']),
        );
        if (\is_wp_error($result)) {
            throw new \RuntimeException(
                'wp_insert_term failed: ' . $result->get_error_message(),
            );
        }

        $termId = (int) $result['term_id'];
        self::applyMeta($parts['meta'], $termId);

        return $termId;
    }

    /**
     * @param list<string>|null $only
     */
    public static function update(
        DataObject $dto,
        ?int $termId = null,
        ?array $only = null,
        bool $strict = false,
        bool $skipNullDeletes = false,
    ): int {
        if ($strict || $skipNullDeletes) {
            self::project($dto, $only, $strict, $skipNullDeletes);
        }
        $parts = self::toArgs($dto, $only);

        $termId ??= $parts['term_id'];
        if ($termId === null || $termId <= 0) {
            throw MissingIdentifierException::forUpdate($dto::class, 'term_id');
        }
        if ($parts['taxonomy'] === null) {
            throw new \RuntimeException(sprintf(
                'Cannot update term from %s: `taxonomy` is required by WP wp_update_term().',
                $dto::class,
            ));
        }

        $args = $parts['args'];
        if ($parts['name'] !== null) {
            $args['name'] = $parts['name'];
        }

        $result = \wp_update_term($termId, $parts['taxonomy'], \wp_slash($args));
        if (\is_wp_error($result)) {
            throw new \RuntimeException(
                'wp_update_term failed: ' . $result->get_error_message(),
            );
        }

        self::applyMeta($parts['meta'], $termId);

        return $termId;
    }

    /**
     * @param list<string>|null $only
     */
    public static function save(
        DataObject $dto,
        ?array $only = null,
        bool $strict = false,
        bool $skipNullDeletes = false,
    ): int {
        $parts = self::toArgs($dto, $only);
        $termId = $parts['term_id'];

        return ($termId !== null && $termId > 0)
            ? self::update($dto, $termId, $only, $strict, $skipNullDeletes)
            : self::insert($dto, $only, $strict, $skipNullDeletes);
    }

    /**
     * @param list<string>|null $only
     * @return array{system: array<string, mixed>, meta: array<string, mixed>, metaToDelete: list<string>}
     */
    private static function project(
        DataObject $dto,
        ?array $only,
        bool $strict = false,
        bool $skipNullDeletes = false,
    ): array {
        return SinkProjection::project(
            $dto,
            TermField::class,
            self::TERM_FIELDS,
            propertyAliases: ['id' => 'term_id'],
            excludeSystemFields: self::WRITE_IGNORED,
            only: $only,
            strict: $strict,
            skipNullDeletes: $skipNullDeletes,
        );
    }

    /**
     * @param array{write: array<string, mixed>, delete: list<string>} $meta
     */
    private static function applyMeta(array $meta, int $termId): void
    {
        foreach ($meta['write'] as $key => $value) {
            \update_term_meta($termId, $key, \wp_slash($value));
        }
        foreach ($meta['delete'] as $key) {
            \delete_term_meta($termId, $key);
        }
    }
}
