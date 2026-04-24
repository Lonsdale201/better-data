<?php

declare(strict_types=1);

namespace BetterData\Sink;

use BetterData\Attribute\UserField;
use BetterData\DataObject;
use BetterData\Exception\MissingIdentifierException;
use BetterData\Internal\SinkProjection;

/**
 * Writes DataObjects back to the WordPress users store.
 *
 * Mirrors PostSink. Security policy:
 *
 *   `user_pass` and `user_activation_key` are ALWAYS excluded from sink
 *   output. Passwords need to be handled via `wp_set_password()` or a
 *   dedicated explicit write path — never through generic DTO round-trip
 *   serialization. (WP 6.8+ moved to bcrypt, making this even more
 *   important: a round-tripped hash is opaque and dangerous to re-persist.)
 *
 *   If your DTO declares `user_pass` / `user_activation_key`, they are
 *   silently dropped here — document this clearly for your team.
 *
 * Slashing policy: see PostSink. Convenience methods slash via
 * `wp_slash()`; projections stay raw.
 */
final class UserSink
{
    /**
     * @var list<string>
     */
    private const USER_FIELDS = [
        'ID',
        'user_login',
        'user_pass',
        'user_nicename',
        'user_email',
        'user_url',
        'user_registered',
        'user_activation_key',
        'user_status',
        'display_name',
    ];

    /**
     * @var list<string>
     */
    private const ALWAYS_EXCLUDED = ['user_pass', 'user_activation_key'];

    /**
     * @var list<string>
     */
    private const GMT_FIELDS = ['user_registered'];

    /**
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public static function toArgs(DataObject $dto, ?array $only = null): array
    {
        return self::project($dto, $only)['system'];
    }

    /**
     * @param list<string>|null $only
     * @return array{write: array<string, mixed>, delete: list<string>}
     */
    public static function toMeta(DataObject $dto, ?array $only = null): array
    {
        $projection = self::project($dto, $only);

        return [
            'write' => $projection['meta'],
            'delete' => $projection['metaToDelete'],
        ];
    }

    /**
     * @param list<string>|null $only
     */
    public static function insert(DataObject $dto, ?array $only = null, bool $strict = false): int
    {
        $projection = self::project($dto, $only, $strict);
        $args = $projection['system'];
        unset($args['ID']);

        $result = \wp_insert_user(\wp_slash($args));
        if (\is_wp_error($result)) {
            throw new \RuntimeException(
                'wp_insert_user failed: ' . $result->get_error_message(),
            );
        }

        $userId = (int) $result;
        self::applyMetaProjection($projection, $userId);

        return $userId;
    }

    /**
     * @param list<string>|null $only
     */
    public static function update(
        DataObject $dto,
        ?int $userId = null,
        ?array $only = null,
        bool $strict = false,
    ): int {
        $userId ??= self::identifierOf($dto);
        if ($userId <= 0) {
            throw MissingIdentifierException::forUpdate($dto::class, 'id');
        }

        $projection = self::project($dto, $only, $strict);
        $args = $projection['system'];
        $args['ID'] = $userId;

        $result = \wp_update_user(\wp_slash($args));
        if (\is_wp_error($result)) {
            throw new \RuntimeException(
                'wp_update_user failed: ' . $result->get_error_message(),
            );
        }

        self::applyMetaProjection($projection, $userId);

        return $userId;
    }

    /**
     * @param list<string>|null $only
     */
    public static function save(DataObject $dto, ?array $only = null, bool $strict = false): int
    {
        $id = self::tryIdentifierOf($dto);

        return $id > 0
            ? self::update($dto, $id, $only, $strict)
            : self::insert($dto, $only, $strict);
    }

    /**
     * @param list<string>|null $only
     * @return array{system: array<string, mixed>, meta: array<string, mixed>, metaToDelete: list<string>}
     */
    private static function project(DataObject $dto, ?array $only, bool $strict = false): array
    {
        return SinkProjection::project(
            $dto,
            UserField::class,
            self::USER_FIELDS,
            propertyAliases: ['id' => 'ID'],
            excludeSystemFields: self::ALWAYS_EXCLUDED,
            only: $only,
            gmtSystemFields: self::GMT_FIELDS,
            strict: $strict,
        );
    }

    /**
     * @param array{system: array<string, mixed>, meta: array<string, mixed>, metaToDelete: list<string>} $projection
     */
    private static function applyMetaProjection(array $projection, int $userId): void
    {
        foreach ($projection['meta'] as $key => $value) {
            \update_user_meta($userId, $key, \wp_slash($value));
        }
        foreach ($projection['metaToDelete'] as $key) {
            \delete_user_meta($userId, $key);
        }
    }

    private static function identifierOf(DataObject $dto): int
    {
        $id = self::tryIdentifierOf($dto);
        if ($id <= 0) {
            throw MissingIdentifierException::forUpdate($dto::class, 'id');
        }

        return $id;
    }

    private static function tryIdentifierOf(DataObject $dto): int
    {
        $args = self::toArgs($dto);

        return isset($args['ID']) ? (int) $args['ID'] : 0;
    }
}
