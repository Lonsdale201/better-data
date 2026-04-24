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
 * `sanitize` (default null) accepts a callable name (string) to install
 * as `sanitize_callback`. Pass WP's own helpers (`'sanitize_text_field'`,
 * `'absint'`, etc.) or your own function. The library intentionally does
 * NOT auto-sanitize through its internal TypeCoercer — that coercer
 * produces PHP-native types, which is the wrong shape for WP-storable
 * scalars, and silently installing it would step on consumers' existing
 * sanitize pipelines. If you need sanitization, supply it explicitly.
 *
 * `authCapability` (default null) installs an auth_callback that defers
 * to `user_can($user_id, $cap, $object_id)`. Null keeps WP's default,
 * which is `__return_true` for non-protected keys and `__return_false`
 * for keys prefixed with `_` exposed to REST — see the protected-meta
 * warning emitted by `MetaKeyRegistry::register()`.
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
        public ?string $sanitize = null,
        public ?string $authCapability = null,
    ) {
    }
}
