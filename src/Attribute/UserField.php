<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Maps a DataObject property to a WP_User system field.
 *
 * Parallel to `#[PostField]`, used when the property name doesn't match
 * the underlying WP_User field:
 *
 *     #[UserField('user_email')]
 *     public string $email,
 *
 *     #[UserField('display_name')]
 *     public string $displayName,
 *
 * If the property name exactly matches a WP_User field (including
 * the `id` → `ID` special case), the attribute is optional.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class UserField
{
    public function __construct(public string $name)
    {
    }
}
