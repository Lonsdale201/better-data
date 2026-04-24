<?php

declare(strict_types=1);

namespace BetterData\Presenter;

use BetterData\DataObject;

/**
 * Presents a collection of DataObjects with one shared configuration.
 *
 * Mirrors the `Presenter` fluent API — each setter records a configurer
 * closure that replays on every per-item Presenter at `toArray()` time.
 * This avoids duplicating the Presenter state-machine while keeping the
 * call site clean.
 *
 * ```php
 * $rows = Presenter::forCollection($products)
 *     ->context(PresentationContext::rest())
 *     ->only(['id', 'title', 'price'])
 *     ->rename('post_title', 'title')
 *     ->toArray();
 * ```
 */
final class CollectionPresenter
{
    /**
     * @var list<\Closure(Presenter): void>
     */
    private array $configurers = [];

    /**
     * @param iterable<DataObject> $dtos
     * @param class-string<Presenter> $presenterClass
     */
    public function __construct(
        private readonly iterable $dtos,
        private readonly string $presenterClass = Presenter::class,
    ) {
    }

    public function context(PresentationContext $context): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->context($context);

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function only(array $fields): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->only($fields);

        return $this;
    }

    /**
     * @param string|list<string>                         $field
     * @param null|callable(PresentationContext): bool    $when
     */
    public function hide(string|array $field, ?callable $when = null): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->hide($field, $when);

        return $this;
    }

    public function hideUnlessCan(string $field, string $capability, mixed ...$args): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->hideUnlessCan($field, $capability, ...$args);

        return $this;
    }

    /**
     * @param list<string> $roles
     */
    public function showOnlyFor(string $field, array $roles): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->showOnlyFor($field, $roles);

        return $this;
    }

    /**
     * @param string|array<string, string> $from
     */
    public function rename(string|array $from, ?string $to = null): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->rename($from, $to);

        return $this;
    }

    public function compute(string $name, \Closure $factory): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->compute($name, $factory);

        return $this;
    }

    public function preset(string $field, \Closure $renderer): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->preset($field, $renderer);

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function includeSensitive(array $fields): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->includeSensitive($fields);

        return $this;
    }

    public function formatDate(string $field, string $format, ?string $as = null): self
    {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->formatDate($field, $format, $as);

        return $this;
    }

    public function formatCurrency(
        string $field,
        ?string $as = null,
        ?string $currency = null,
        bool $html = false,
    ): self {
        $this->configurers[] = static fn (Presenter $p): Presenter => $p->formatCurrency($field, $as, $currency, $html);

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->dtos as $dto) {
            /** @var Presenter $presenter */
            $presenter = ($this->presenterClass)::for($dto);
            foreach ($this->configurers as $configure) {
                $configure($presenter);
            }
            $out[] = $presenter->toArray();
        }

        return $out;
    }

    public function toJson(?int $flags = null): string
    {
        $flags ??= JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return json_encode($this->toArray(), $flags);
    }
}
