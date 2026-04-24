<?php

declare(strict_types=1);

namespace BetterData\Attribute;

use Attribute;

/**
 * Declares that a property's stored representation should be
 * encrypted at rest via `BetterData\Encryption\EncryptionEngine`.
 *
 * Sink-agnostic: honoured by every sink that routes through
 * `SinkProjection` (Post/User/Term) AND by `OptionSink`. Meta reads
 * also decrypt transparently via `AttributeDrivenHydrator` on load.
 *
 * Typical shape:
 *
 *     #[Encrypted]
 *     #[MetaKey('_api_key')]
 *     public Secret $apiKey,
 *
 * The `Secret` type keeps the plaintext leak-proof in memory;
 * `#[Encrypted]` keeps it encrypted at rest. They compose naturally
 * but are independent — `#[Encrypted]` also works on plain `string`
 * properties when the Secret ergonomics are not needed.
 *
 * Key source: `BETTER_DATA_ENCRYPTION_KEY` constant (base64 of 32
 * raw bytes) or `better_data_encryption_key` filter. See
 * {@see \BetterData\Encryption\EncryptionEngine}.
 *
 * ## Interaction with `#[MetaKey(encrypt: true)]`
 *
 * `#[MetaKey(encrypt: true)]` is a shorthand kept for back-compat
 * with the Phase 8.6 initial API; new code should prefer the
 * `#[Encrypted]` attribute because it expresses the concern once and
 * applies across sinks. Either form activates the same engine;
 * setting both is safe (idempotent).
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Encrypted
{
}
