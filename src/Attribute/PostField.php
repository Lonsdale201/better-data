<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Maps a DataObject property to a WP_Post system field.
 *
 * Used when the property name differs from the WP_Post field name,
 * typically when the DTO uses camelCase conventions:
 *
 *     #[PostField('post_title')]
 *     public string $title,
 *
 *     #[PostField('post_modified_gmt')]
 *     public \DateTimeImmutable $modifiedAt,
 *
 * If the property name exactly matches a known WP_Post field
 * (including the lowercase `id` → `ID` special case), the attribute
 * is optional.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class PostField
{
    public function __construct(public string $name)
    {
    }
}
