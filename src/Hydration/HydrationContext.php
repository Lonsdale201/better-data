<?php

declare(strict_types=1);

namespace BetterData\Hydration;

use BetterData\DataObject;

/**
 * Immutable context passed through the hydration pipeline.
 *
 * Middleware receives a context, optionally produces a derived one via
 * `withData()` / `withMeta()`, and forwards it to the next handler.
 * Never mutate the context in place — always return a new instance.
 */
final readonly class HydrationContext
{
    /**
     * @param class-string<DataObject> $targetClass
     * @param array<string, mixed>     $data
     * @param array<string, mixed>     $meta
     */
    public function __construct(
        public string $targetClass,
        public array $data,
        public array $meta = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function withData(array $data): self
    {
        return new self($this->targetClass, $data, $this->meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->targetClass, $this->data, $meta);
    }
}
