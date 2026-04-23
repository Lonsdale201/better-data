<?php

declare(strict_types=1);

namespace BetterData\Sink;

/**
 * Optional convenience trait: exposes `saveAsPost / saveAsUser / saveAsTerm
 * / saveAsOption / saveAsRow` one-liners and their projection counterparts
 * on the DTO class itself.
 *
 * The static sink classes remain the canonical API; this trait is purely
 * syntactic sugar for callers who prefer `$dto->saveAsPost()` over
 * `PostSink::save($dto)`. Use in tandem with `HasWpSources` when a DTO
 * participates in both directions.
 *
 * ```php
 * final readonly class ProductDto extends DataObject
 * {
 *     use HasWpSources;
 *     use HasWpSinks;
 *     // ...
 * }
 *
 * $id = $product->saveAsPost();                 // insert or update
 * $id = $product->saveAsPost(only: ['price']);  // partial update
 * ```
 *
 * @phpstan-require-extends \BetterData\DataObject
 */
trait HasWpSinks
{
    /**
     * @param list<string>|null $only
     */
    public function saveAsPost(?array $only = null): int
    {
        return PostSink::save($this, $only);
    }

    /**
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public function toPostArgs(?array $only = null): array
    {
        return PostSink::toArgs($this, $only);
    }

    /**
     * @param list<string>|null $only
     */
    public function saveAsUser(?array $only = null): int
    {
        return UserSink::save($this, $only);
    }

    /**
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public function toUserArgs(?array $only = null): array
    {
        return UserSink::toArgs($this, $only);
    }

    /**
     * @param list<string>|null $only
     */
    public function saveAsTerm(?array $only = null): int
    {
        return TermSink::save($this, $only);
    }

    /**
     * @param list<string>|null $only
     */
    public function saveAsOption(string $option, ?array $only = null, ?bool $autoload = null): bool
    {
        return OptionSink::save($this, $option, $only, $autoload);
    }

    /**
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public function toOptionArray(?array $only = null): array
    {
        return OptionSink::toArray($this, $only);
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string>|null    $only
     * @param list<string>|null    $formats
     * @param list<string>|null    $whereFormats
     */
    public function saveAsRow(
        \wpdb $wpdb,
        string $table,
        array $where = [],
        ?array $only = null,
        ?array $formats = null,
        ?array $whereFormats = null,
    ): int {
        return $where === []
            ? RowSink::insert($wpdb, $table, $this, $only, $formats)
            : RowSink::update($wpdb, $table, $this, $where, $only, $formats, $whereFormats);
    }

    /**
     * @param list<string>|null $only
     * @return array<string, mixed>
     */
    public function toRowArray(?array $only = null): array
    {
        return RowSink::toArray($this, $only);
    }
}
