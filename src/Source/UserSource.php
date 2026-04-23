<?php

declare(strict_types=1);

namespace BetterData\Source;

use BetterData\Attribute\UserField;
use BetterData\DataObject;
use BetterData\Exception\UserNotFoundException;
use BetterData\Internal\AttributeDrivenHydrator;

/**
 * Hydrates DataObjects from WP_User records and their user meta.
 *
 * Same mapping rules as PostSource, applied to WP_User:
 *  - `#[MetaKey('x')]`   → `get_user_meta($id, 'x', true)`
 *  - `#[UserField('x')]` → `$user->x` (or `$user->data->x` — see below)
 *  - Property name matches a WP_User field (`id` → `ID`) → auto
 *
 * WP_User exposes its row data via `->data` (an object), and convenience
 * accessors on `$user` itself for most fields. For robustness the adapter
 * flattens `$user->data` into a plain associative array before delegating.
 *
 * Bulk hydration prewarms with `update_meta_cache('user', $ids)`.
 */
final class UserSource
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
     * @template T of DataObject
     * @param int|\WP_User    $user
     * @param class-string<T> $dtoClass
     * @return T
     */
    public static function hydrate(int|\WP_User $user, string $dtoClass): DataObject
    {
        $wpUser = $user instanceof \WP_User ? $user : \get_user_by('id', $user);
        if (!$wpUser instanceof \WP_User || (int) $wpUser->ID === 0) {
            throw UserNotFoundException::forId($dtoClass, is_int($user) ? $user : 0);
        }

        $fields = self::flattenUserFields($wpUser);
        $userId = (int) $wpUser->ID;

        return AttributeDrivenHydrator::hydrate(
            $dtoClass,
            $fields,
            self::USER_FIELDS,
            UserField::class,
            static fn (string $key): mixed => \get_user_meta($userId, $key, true),
            ['id' => 'ID'],
        );
    }

    /**
     * @template T of DataObject
     * @param list<int>       $userIds
     * @param class-string<T> $dtoClass
     * @return list<T>
     */
    public static function hydrateMany(array $userIds, string $dtoClass): array
    {
        if ($userIds === []) {
            return [];
        }

        if (\function_exists('update_meta_cache')) {
            \update_meta_cache('user', $userIds);
        }

        $out = [];
        foreach ($userIds as $id) {
            $wpUser = \get_user_by('id', $id);
            if ($wpUser instanceof \WP_User) {
                $out[] = self::hydrate($wpUser, $dtoClass);
            }
        }

        return $out;
    }

    /**
     * WP_User stores the row on `->data` (stdClass). Merge that with the
     * `ID` accessor so auto-detection and explicit `#[UserField]` both work.
     *
     * @return array<string, mixed>
     */
    private static function flattenUserFields(\WP_User $user): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $user->data;
        $data['ID'] = (int) $user->ID;

        return $data;
    }
}
