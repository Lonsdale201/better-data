<?php

declare(strict_types=1);

namespace BetterData\Tests\Fixtures;

use BetterData\Attribute\MetaKey;
use BetterData\Attribute\PostField;
use BetterData\DataObject;
use DateTimeImmutable;

/**
 * Fixture representing a typical DTO mapped onto a WP post:
 *  - `id` (auto → ID)
 *  - `post_title` (auto)
 *  - `title` renamed via PostField('post_title')  — NOT used here; we keep both explicit and auto
 *  - `publishedAt` renamed via PostField('post_date_gmt')
 *  - price, stock from meta
 *  - description nullable meta (absent → null)
 */
final readonly class PostBackedDto extends DataObject
{
    public function __construct(
        public int $id,
        public string $post_title,
        public string $post_status,
        #[PostField('post_date_gmt')]
        public DateTimeImmutable $publishedAt,
        #[MetaKey('_price')]
        public float $price,
        #[MetaKey('_stock')]
        public int $stock,
        #[MetaKey('_description')]
        public ?string $description = null,
    ) {
    }
}
