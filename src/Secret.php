<?php

declare(strict_types=1);

namespace BetterData;

use BetterData\Exception\SecretSerializationException;
use JsonSerializable;
use Stringable;

/**
 * Leak-proof container for sensitive string values (API keys, tokens,
 * OAuth secrets, webhook signing keys).
 *
 * Declared as a DataObject property type, every accidental serialization
 * path redacts to `'***'`:
 *
 *   `(string)$secret`             → '***'
 *   `json_encode($secret)`        → '"***"'
 *   `var_dump($secret)`           → object(Secret) { ["value"]=> string(3) "***" }
 *   `print_r($secret, true)`      → (same via __debugInfo)
 *   `$dto->toArray()`             → the Secret property becomes '***'
 *   `Presenter::for($dto)->toArray()` → Secret-typed properties are
 *                                       excluded by default (like
 *                                       `#[Sensitive]`), and even if
 *                                       included via
 *                                       `includeSensitive([...])` they
 *                                       render as '***' — caller must
 *                                       call `->reveal()` explicitly.
 *
 * PHP's built-in `serialize()` / `unserialize()` are blocked with a
 * `SecretSerializationException` rather than silently redacting,
 * because a caller that tried to serialize a Secret has almost
 * certainly made a mistake that would be much worse if it silently
 * succeeded with lossy or leak-y output.
 *
 * Comparison uses `hash_equals` for constant-time equality. Reflection
 * can still read the private property — this is a PHP limitation; the
 * class defends against accidental leaks through common paths, not
 * against a determined introspector.
 *
 * Usage:
 *
 *     final readonly class ApiCredentialsDto extends DataObject
 *     {
 *         public function __construct(
 *             public string $clientId,
 *             public Secret $clientSecret,
 *         ) {}
 *     }
 *
 *     $dto = ApiCredentialsDto::fromArray([
 *         'clientId' => 'app-1',
 *         'clientSecret' => 'sk_live_xxx',
 *     ]);
 *     $dto->clientSecret instanceof Secret; // true
 *     $dto->clientSecret->reveal();         // 'sk_live_xxx'
 *     (string) $dto->clientSecret;          // '***'
 */
final class Secret implements Stringable, JsonSerializable
{
    public function __construct(private readonly string $value)
    {
    }

    /**
     * The ONLY way to get the raw value. Deliberately explicit — every
     * call site that reveals a secret should be reviewable as such.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * Constant-time equality. Accepts another Secret or a raw string
     * so callers can compare without unwrapping the secret side.
     */
    public function equals(self|string $other): bool
    {
        return hash_equals($this->value, $other instanceof self ? $other->value : $other);
    }

    public function __toString(): string
    {
        return '***';
    }

    public function jsonSerialize(): string
    {
        return '***';
    }

    /**
     * Controls `var_dump` / `print_r` output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw SecretSerializationException::forSerialize();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw SecretSerializationException::forUnserialize();
    }
}
