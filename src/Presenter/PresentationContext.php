<?php

declare(strict_types=1);

namespace BetterData\Presenter;

/**
 * Context carried through a Presenter run.
 *
 * Holds the stringly-named context (`rest`, `admin`, `email`, `csv`, or
 * whatever the consumer invents), plus optional user / locale / timezone
 * / free-form meta used by field predicates, computed-field closures
 * and formatter helpers.
 *
 * Immutable — `withName()` / `withUserId()` / etc. return new instances.
 *
 * Factory shortcuts:
 *   - `rest()`  / `admin()` — infer the current WP user if available
 *   - `email()` — no user context by default
 *   - `none()`  — guest / unknown
 */
final readonly class PresentationContext
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $name,
        public ?int $userId = null,
        public ?string $locale = null,
        public ?string $timezone = null,
        public array $meta = [],
    ) {
    }

    public static function rest(?int $userId = null): self
    {
        return new self('rest', $userId ?? self::currentUserId());
    }

    public static function admin(?int $userId = null): self
    {
        return new self('admin', $userId ?? self::currentUserId());
    }

    public static function email(?int $userId = null, ?string $locale = null): self
    {
        return new self('email', $userId, $locale);
    }

    public static function none(): self
    {
        return new self('none');
    }

    public function withName(string $name): self
    {
        return new self($name, $this->userId, $this->locale, $this->timezone, $this->meta);
    }

    public function withUserId(?int $userId): self
    {
        return new self($this->name, $userId, $this->locale, $this->timezone, $this->meta);
    }

    public function withLocale(?string $locale): self
    {
        return new self($this->name, $this->userId, $locale, $this->timezone, $this->meta);
    }

    public function withTimezone(?string $timezone): self
    {
        return new self($this->name, $this->userId, $this->locale, $timezone, $this->meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->name, $this->userId, $this->locale, $this->timezone, $meta);
    }

    public function userCan(string $capability, mixed ...$args): bool
    {
        if ($this->userId === null) {
            return false;
        }

        if (!\function_exists('user_can')) {
            return false;
        }

        return (bool) \user_can($this->userId, $capability, ...$args);
    }

    /**
     * @return list<string>
     */
    public function userRoles(): array
    {
        if ($this->userId === null || !\function_exists('get_userdata')) {
            return [];
        }

        $user = \get_userdata($this->userId);
        if (!$user instanceof \WP_User) {
            return [];
        }

        /** @var list<string> */
        return array_values($user->roles);
    }

    private static function currentUserId(): ?int
    {
        if (!\function_exists('get_current_user_id')) {
            return null;
        }

        $id = (int) \get_current_user_id();

        return $id > 0 ? $id : null;
    }
}
