<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Exception\TypeCoercionException;
use BetterData\Tests\Fixtures\AddressDto;
use BetterData\Tests\Fixtures\InvoiceDto;
use PHPUnit\Framework\TestCase;

final class ListOfTest extends TestCase
{
    public function testElementsCoercedFromArrayToDataObject(): void
    {
        $dto = InvoiceDto::fromArray([
            'number' => 'INV-001',
            'billingAddresses' => [
                ['city' => 'Budapest', 'country' => 'HU'],
                ['city' => 'Berlin', 'country' => 'DE', 'zip' => '10115'],
            ],
        ]);

        self::assertCount(2, $dto->billingAddresses);
        self::assertInstanceOf(AddressDto::class, $dto->billingAddresses[0]);
        self::assertSame('Budapest', $dto->billingAddresses[0]->city);
        self::assertInstanceOf(AddressDto::class, $dto->billingAddresses[1]);
        self::assertSame('10115', $dto->billingAddresses[1]->zip);
    }

    public function testExistingInstancesPassThrough(): void
    {
        $dto = InvoiceDto::fromArray([
            'number' => 'INV-002',
            'billingAddresses' => [
                new AddressDto('Paris', 'FR'),
            ],
        ]);

        self::assertSame('Paris', $dto->billingAddresses[0]->city);
    }

    public function testEmptyListWorks(): void
    {
        $dto = InvoiceDto::fromArray(['number' => 'INV-003']);

        self::assertSame([], $dto->billingAddresses);
    }

    public function testBadElementThrows(): void
    {
        $this->expectException(TypeCoercionException::class);
        InvoiceDto::fromArray([
            'number' => 'INV-004',
            'billingAddresses' => ['just-a-string'],
        ]);
    }

    public function testRoundTripThroughToArray(): void
    {
        $dto = InvoiceDto::fromArray([
            'number' => 'INV-005',
            'billingAddresses' => [
                ['city' => 'A', 'country' => 'HU'],
                ['city' => 'B', 'country' => 'DE'],
            ],
        ]);

        // toArray unwraps each nested DataObject
        $flat = $dto->toArray();
        self::assertIsArray($flat['billingAddresses']);
        self::assertIsArray($flat['billingAddresses'][0]);
        self::assertSame('A', $flat['billingAddresses'][0]['city']);

        // Re-hydrate
        $again = InvoiceDto::fromArray($flat);
        self::assertInstanceOf(AddressDto::class, $again->billingAddresses[0]);
        self::assertSame('A', $again->billingAddresses[0]->city);
    }
}
