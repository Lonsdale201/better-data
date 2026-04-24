<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\MetaKey;
use BetterData\DataObject;

final readonly class ArrayMetaDto extends DataObject
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[MetaKey('tags')]
        public array $tags = [],
    ) {
    }
}
