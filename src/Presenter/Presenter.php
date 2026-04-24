<?php

declare(strict_types=1);

namespace BetterData\Presenter;

use BackedEnum;
use BetterData\Attribute\Sensitive;
use BetterData\DataObject;
use BetterData\Exception\UnknownFieldException;
use BetterData\Presenter\Formatter\CurrencyFormatter;
use BetterData\Presenter\Formatter\DateTimeFormatter;
use DateTimeInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * Fluent builder that projects a DataObject into a context-specific
 * output shape.
 *
 * Primary API: call-site configuration.
 *
 * ```php
 * $data = Presenter::for($product)
 *     ->context(PresentationContext::rest())
 *     ->only(['id', 'title', 'price', 'formattedPrice'])
 *     ->rename('post_title', 'title')
 *     ->compute('formattedPrice', fn($p, $ctx) => wc_price($p->price))
 *     ->hideUnlessCan('cost', 'manage_options')
 *     ->toArray();
 * ```
 *
 * Advanced API: extend Presenter and override `configure()` for DTOs
 * that warrant a dedicated class.
 *
 * ```php
 * final class ProductPresenter extends Presenter
 * {
 *     protected function configure(): void
 *     {
 *         $this
 *             ->rename('post_title', 'title')
 *             ->compute('formattedPrice', fn ($p) => wc_price($p->price));
 *     }
 * }
 * ```
 *
 * Collection variant: `Presenter::forCollection($dtos)`.
 *
 * Ordering of operations inside `toArray()`:
 *   1. Collect raw DTO property values
 *   2. Apply per-field preset renderers (override nested DTO rendering)
 *   3. Recurse on remaining DataObject / array-of-DataObject values
 *   4. Merge in computed fields (computed AFTER so they can override props)
 *   5. Apply `only` whitelist
 *   6. Apply `hide` predicates
 *   7. Apply rename map
 *
 * JSON output is a thin wrapper over `toArray`.
 *
 * @phpstan-consistent-constructor
 */
class Presenter
{
    protected PresentationContext $context;

    /**
     * @var list<string>|null
     */
    private ?array $only = null;

    /**
     * @var array<string, callable(PresentationContext): bool|null>
     */
    private array $hidden = [];

    /**
     * @var array<string, string>
     */
    private array $rename = [];

    /**
     * @var array<string, \Closure>
     */
    private array $computed = [];

    /**
     * @var array<string, \Closure>
     */
    private array $presets = [];

    /**
     * @var list<string>
     */
    private array $includeSensitive = [];

    public function __construct(protected readonly DataObject $dto)
    {
        $this->context = PresentationContext::none();
        $this->configure();
    }

    /**
     * @return static
     */
    public static function for(DataObject $dto): static
    {
        return new static($dto);
    }

    /**
     * @param iterable<DataObject> $dtos
     */
    public static function forCollection(iterable $dtos): CollectionPresenter
    {
        return new CollectionPresenter($dtos, static::class);
    }

    /**
     * Override point for subclasses to pre-wire configuration.
     */
    protected function configure(): void
    {
    }

    public function context(PresentationContext $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Restrict the output to the listed field names. `null` (default)
     * keeps everything. Computed fields must be listed here if `only()`
     * is set — their names count as field names.
     *
     * Pass `strict: true` to get a typo-safe call: any field name that
     * is neither a DTO property nor a registered `compute()` name
     * throws `UnknownFieldException`. Off by default for backward
     * compat.
     *
     * @param list<string> $fields
     */
    public function only(array $fields, bool $strict = false): static
    {
        if ($strict) {
            $this->assertKnownOnlyFields($fields);
        }
        $this->only = $fields;

        return $this;
    }

    /**
     * Hide a field from the output. With `$when`, hide only when the
     * predicate returns true given the context.
     *
     * @param string|list<string>                         $field
     * @param null|callable(PresentationContext): bool    $when
     */
    public function hide(string|array $field, ?callable $when = null): static
    {
        $fields = is_array($field) ? $field : [$field];
        foreach ($fields as $f) {
            $this->hidden[$f] = $when;
        }

        return $this;
    }

    /**
     * Hide unless the current context user has the given capability.
     */
    public function hideUnlessCan(string $field, string $capability, mixed ...$args): static
    {
        return $this->hide(
            $field,
            static fn (PresentationContext $ctx): bool => !$ctx->userCan($capability, ...$args),
        );
    }

    /**
     * Hide unless the current context user has at least one of the given roles.
     *
     * @param list<string> $roles
     */
    public function showOnlyFor(string $field, array $roles): static
    {
        return $this->hide(
            $field,
            static fn (PresentationContext $ctx): bool => array_intersect($ctx->userRoles(), $roles) === [],
        );
    }

    /**
     * Rename output key(s). Call with two strings for a single rename,
     * or with an associative array for bulk.
     *
     * @param string|array<string, string> $from
     */
    public function rename(string|array $from, ?string $to = null): static
    {
        if (is_array($from)) {
            $this->rename = array_replace($this->rename, $from);
        } else {
            if ($to === null) {
                throw new \InvalidArgumentException('rename() requires a target name when given a string source.');
            }
            $this->rename[$from] = $to;
        }

        return $this;
    }

    /**
     * Add or override a field with a computed value. Lazy — the closure
     * is only invoked if the field survives the `only` / `hide` filters.
     *
     * Signature: `fn (DataObject $dto, PresentationContext $ctx): mixed`.
     * Users may narrow the `$dto` parameter to their concrete DTO type.
     */
    public function compute(string $name, \Closure $factory): static
    {
        $this->computed[$name] = $factory;

        return $this;
    }

    /**
     * Override the rendering of a nested field. Useful when the default
     * recursive presentation isn't what you want for a specific prop.
     *
     * Signature: `fn (mixed $value, PresentationContext $ctx): mixed`.
     */
    public function preset(string $field, \Closure $renderer): static
    {
        $this->presets[$field] = $renderer;

        return $this;
    }

    /**
     * Opt in to include otherwise-sensitive fields (properties carrying
     * `#[Sensitive]`). Pass a whitelist of property names; anything
     * outside the list stays redacted.
     *
     * @param list<string> $fields
     */
    public function includeSensitive(array $fields): static
    {
        $this->includeSensitive = $fields;

        return $this;
    }

    /**
     * Format a DateTime property through the `DateTimeFormatter` (locale
     * + timezone aware via context). If `$as` is null, the original
     * field value is replaced with the formatted string; otherwise a new
     * field is added under `$as`.
     */
    public function formatDate(string $field, string $format, ?string $as = null): static
    {
        $target = $as ?? $field;
        $this->computed[$target] = function (DataObject $dto, PresentationContext $ctx) use ($field, $format): ?string {
            $value = $dto->{$field} ?? null;
            if (!$value instanceof DateTimeInterface) {
                return null;
            }

            return (new DateTimeFormatter($ctx))->format($value, $format);
        };

        return $this;
    }

    /**
     * Format a numeric property through the `CurrencyFormatter`
     * (WooCommerce-aware when available, plain fallback otherwise).
     *
     * If `$as` is null, the original value is replaced; else a new field
     * is added under `$as`. `$currency` overrides the default/WC code
     * per call. `$html` emits the WC HTML wrapper when WC is loaded.
     */
    public function formatCurrency(
        string $field,
        ?string $as = null,
        ?string $currency = null,
        bool $html = false,
    ): static {
        $target = $as ?? $field;
        $this->computed[$target] = function (DataObject $dto, PresentationContext $ctx) use ($field, $currency, $html): ?string {
            $value = $dto->{$field} ?? null;
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            $formatter = new CurrencyFormatter($ctx, $currency);

            return $html ? $formatter->formatHtml($value, $currency) : $formatter->format($value, $currency);
        };

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $sensitive = $this->sensitiveFieldNames();
        $out = $this->collectDtoFields();

        foreach ($sensitive as $name) {
            if (!in_array($name, $this->includeSensitive, true)) {
                unset($out[$name]);
            }
        }

        foreach ($this->computed as $name => $factory) {
            if (!$this->isFieldSurviving($name)) {
                continue;
            }
            $out[$name] = $factory($this->dto, $this->context);
        }

        if ($this->only !== null) {
            $allowed = array_flip($this->only);
            $out = array_intersect_key($out, $allowed);
        }

        foreach (array_keys($out) as $name) {
            if ($this->shouldHide($name)) {
                unset($out[$name]);
            }
        }

        if ($this->rename !== []) {
            $renamed = [];
            foreach ($out as $key => $value) {
                $target = $this->rename[$key] ?? $key;
                if (array_key_exists($target, $renamed)) {
                    throw new \LogicException(sprintf(
                        'rename() collision: source keys "%s" and "%s" both map to output key "%s". '
                        . 'Drop one rename or hide the source field before rendering.',
                        array_search($renamed[$target], $out, true),
                        $key,
                        $target,
                    ));
                }
                $renamed[$target] = $value;
            }
            $out = $renamed;
        }

        return $out;
    }

    public function toJson(?int $flags = null): string
    {
        $flags ??= JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        // Force JSON_THROW_ON_ERROR on regardless of caller-supplied
        // flags so the declared `string` return type holds — json_encode
        // would otherwise return `false` on failure.
        $flags |= JSON_THROW_ON_ERROR;

        return json_encode($this->toArray(), $flags);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectDtoFields(): array
    {
        $reflection = new ReflectionClass($this->dto);
        $out = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $name = $property->getName();
            $value = $property->getValue($this->dto);
            $out[$name] = $this->renderValue($name, $value);
        }

        return $out;
    }

    private function renderValue(string $fieldName, mixed $value): mixed
    {
        if (isset($this->presets[$fieldName])) {
            return ($this->presets[$fieldName])($value, $this->context);
        }

        if ($value instanceof DataObject) {
            return self::for($value)->context($this->context)->toArray();
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item) => $item instanceof DataObject
                    ? self::for($item)->context($this->context)->toArray()
                    : $this->serializeScalar($item),
                $value,
            );
        }

        return $this->serializeScalar($value);
    }

    private function serializeScalar(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return $value;
    }

    private function isFieldSurviving(string $name): bool
    {
        if ($this->only !== null && !in_array($name, $this->only, true)) {
            return false;
        }
        if ($this->shouldHide($name)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $fields
     */
    private function assertKnownOnlyFields(array $fields): void
    {
        $reflection = new ReflectionClass($this->dto);
        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $properties[] = $property->getName();
            }
        }
        $available = array_values(array_unique(array_merge($properties, array_keys($this->computed))));
        $unknown = array_values(array_diff($fields, $available));
        if ($unknown !== []) {
            throw UnknownFieldException::forFields($this->dto::class, $unknown, $available);
        }
    }

    /**
     * @return list<string>
     */
    private function sensitiveFieldNames(): array
    {
        $names = [];
        $reflection = new ReflectionClass($this->dto);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if ($property->getAttributes(Sensitive::class) !== []) {
                $names[] = $property->getName();
            }
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->getAttributes(Sensitive::class) !== []) {
                    $name = $parameter->getName();
                    if (!in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    private function shouldHide(string $name): bool
    {
        if (!array_key_exists($name, $this->hidden)) {
            return false;
        }
        $predicate = $this->hidden[$name];
        if ($predicate === null) {
            return true;
        }

        return (bool) $predicate($this->context);
    }
}
