<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Marks a DataObject property as sensitive.
 *
 * Sensitive fields are excluded from `Presenter::toArray()` /
 * `CollectionPresenter::toArray()` output by default. To include one,
 * opt in explicitly per render call:
 *
 *     Presenter::for($dto)->includeSensitive(['apiKey'])->toArray();
 *
 * The attribute does not affect hydration or sinks — it's a presentation
 * guardrail only. For write-side policy (e.g. password hashes never
 * round-tripping), see `UserSink::$alwaysExcluded`.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Sensitive
{
}
