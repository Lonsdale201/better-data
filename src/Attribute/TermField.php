<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Maps a DataObject property to a WP_Term system field.
 *
 * Parallel to `#[PostField]` / `#[UserField]`:
 *
 *     #[TermField('term_id')]
 *     public int $id,
 *
 *     #[TermField('taxonomy')]
 *     public string $taxonomy,
 *
 * If the property name matches a WP_Term field, the attribute is optional
 * (`id` auto-aliases to `term_id`).
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class TermField
{
    public function __construct(public string $name)
    {
    }
}
