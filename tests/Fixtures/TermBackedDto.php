<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\MetaKey;
use BetterData\DataObject;

final readonly class TermBackedDto extends DataObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $taxonomy,
        #[MetaKey('color')]
        public ?string $color = null,
    ) {
    }
}
