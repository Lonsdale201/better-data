<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\Attribute\TermField;
use BetterData\DataObject;
use BetterData\Exception\TermNotFoundException;
use BetterData\Internal\AttributeDrivenHydrator;

/**
 * Hydrates DataObjects from WP_Term records and their term meta.
 *
 * Mapping rules, parallel to PostSource/UserSource:
 *  - `#[MetaKey('x')]`   → `get_term_meta($term_id, 'x', true)`
 *  - `#[TermField('x')]` → `$term->x`
 *  - Property name matches a WP_Term field → auto
 *  - `id` auto-aliases to `term_id` (WP_Term's primary key is term_id, not ID)
 *
 * Bulk hydration prewarms with `update_meta_cache('term', $ids)`.
 */
final class TermSource
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
     * @template T of DataObject
     * @param int|\WP_Term    $term
     * @param class-string<T> $dtoClass
     * @return T
     */
    public static function hydrate(int|\WP_Term $term, string $dtoClass): DataObject
    {
        $wpTerm = $term instanceof \WP_Term ? $term : \get_term($term);
        if (!$wpTerm instanceof \WP_Term) {
            throw TermNotFoundException::forId($dtoClass, is_int($term) ? $term : 0);
        }

        /** @var array<string, mixed> $termFields */
        $termFields = $wpTerm->to_array();
        $termId = (int) $wpTerm->term_id;

        return AttributeDrivenHydrator::hydrate(
            $dtoClass,
            $termFields,
            self::TERM_FIELDS,
            TermField::class,
            static function (string $key) use ($termId): mixed {
                if (\function_exists('metadata_exists')
                    && !\metadata_exists('term', $termId, $key)) {
                    return null;
                }

                return \get_term_meta($termId, $key, true);
            },
            ['id' => 'term_id'],
        );
    }

    /**
     * @template T of DataObject
     * @param list<int>       $termIds
     * @param class-string<T> $dtoClass
     * @return list<T>
     */
    public static function hydrateMany(array $termIds, string $dtoClass): array
    {
        if ($termIds === []) {
            return [];
        }

        if (\function_exists('update_meta_cache')) {
            \update_meta_cache('term', $termIds);
        }

        $out = [];
        foreach ($termIds as $id) {
            $wpTerm = \get_term($id);
            if ($wpTerm instanceof \WP_Term) {
                $out[] = self::hydrate($wpTerm, $dtoClass);
            }
        }

        return $out;
    }
}
