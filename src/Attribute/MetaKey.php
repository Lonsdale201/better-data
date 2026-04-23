<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Marks a DataObject property as backed by a WordPress meta entry.
 *
 * Base usage (hydration / persistence only):
 *
 *     #[MetaKey('_price')]
 *     public float $price,
 *
 * Registration-aware usage (Phase 8) — additional optional parameters
 * describe the shape for `MetaKeyRegistry::register()`:
 *
 *     #[MetaKey(
 *         '_price',
 *         type: 'number',
 *         showInRest: true,
 *         default: 0.0,
 *         description: 'Product unit price',
 *     )]
 *     public float $price,
 *
 * The extra params are inert at hydration/persistence time; they only
 * activate when the consumer explicitly calls `MetaKeyRegistry::register()`.
 * If `$type` is null, it's inferred from the property's PHP type at
 * registration time.
 *
 * `autoSanitize` (default false) opts into a registry-installed
 * sanitize_callback that routes writes through the library's TypeCoercer.
 * Left off by default to avoid stepping on consumers' existing pipelines.
 *
 * `authCapability` (default null) installs an auth_callback that defers
 * to `current_user_can($cap)`. Null keeps WP's default permissive behaviour.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MetaKey
{
    public function __construct(
        public string $key,
        public ?string $type = null,
        public bool $showInRest = false,
        public bool $single = true,
        public mixed $default = null,
        public ?string $description = null,
        public bool $autoSanitize = false,
        public ?string $authCapability = null,
    ) {
    }
}
