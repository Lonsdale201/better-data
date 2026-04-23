<?php

declare(strict_types=1);

namespace BetterData\Presenter;

/**
 * Optional convenience trait: `$dto->present()` returns a fresh Presenter
 * keyed to this DTO. Mirrors the opt-in pattern of `HasWpSources` /
 * `HasWpSinks` — the canonical API remains `Presenter::for($dto)`, the
 * trait is pure DX sugar.
 *
 * @phpstan-require-extends \BetterData\DataObject
 */
trait HasPresenter
{
    public function present(): Presenter
    {
        return Presenter::for($this);
    }
}
