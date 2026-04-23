<?php

declare(strict_types=1);

namespace BetterData\Hydration;

use BetterData\DataObject;
use InvalidArgumentException;

/**
 * Hydration pipeline entry point.
 *
 * Runs a chain of `HydrationMiddleware` in declaration order, then
 * delegates terminal construction to `DataObject::fromArray()`.
 *
 * Typical shape:
 *
 *     $dto = Hydrator::from($payload)
 *         ->through(new RenameFieldsMiddleware(['user_email' => 'email']))
 *         ->through(new InjectDefaultsMiddleware(['source' => 'webhook']))
 *         ->into(CustomerDto::class);
 *
 * The builder is single-shot: `into()` consumes it. To reuse a pipeline
 * configuration, capture the middleware list outside and rebuild per call.
 */
final class Hydrator
{
    /**
     * @var list<HydrationMiddleware>
     */
    private array $middleware = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    private function __construct(
        private readonly array $data,
        private readonly array $meta,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta Optional metadata bag accessible to middleware
     */
    public static function from(array $data, array $meta = []): self
    {
        return new self($data, $meta);
    }

    public function through(HydrationMiddleware ...$middleware): self
    {
        foreach ($middleware as $mw) {
            $this->middleware[] = $mw;
        }

        return $this;
    }

    /**
     * Execute the pipeline and return a hydrated DataObject.
     *
     * @template T of DataObject
     * @param class-string<T> $class
     * @return T
     */
    public function into(string $class): DataObject
    {
        if (!is_subclass_of($class, DataObject::class)) {
            throw new InvalidArgumentException(sprintf(
                'Hydrator::into() expects a subclass of %s, got "%s".',
                DataObject::class,
                $class,
            ));
        }

        $context = new HydrationContext($class, $this->data, $this->meta);
        $pipeline = $this->buildPipeline();

        $result = $pipeline($context);

        if (!$result instanceof $class) {
            throw new InvalidArgumentException(sprintf(
                'Hydrator pipeline produced "%s", expected instance of "%s".',
                $result::class,
                $class,
            ));
        }

        return $result;
    }

    /**
     * @return callable(HydrationContext): DataObject
     */
    private function buildPipeline(): callable
    {
        $terminal = static function (HydrationContext $context): DataObject {
            /** @var class-string<DataObject> $class */
            $class = $context->targetClass;

            return $class::fromArray($context->data);
        };

        return array_reduce(
            array_reverse($this->middleware),
            static fn (callable $next, HydrationMiddleware $mw): callable =>
                static fn (HydrationContext $ctx): DataObject => $mw->process($ctx, $next),
            $terminal,
        );
    }
}
