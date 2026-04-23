<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Internal\RestSchemaBuilder;
use BetterData\Tests\Fixtures\PostBackedDto;
use BetterData\Tests\Fixtures\ProfileDto;
use BetterData\Tests\Fixtures\SchemaTestDto;
use PHPUnit\Framework\TestCase;

final class RestSchemaBuilderTest extends TestCase
{
    public function testRootTypeIsObjectWithProperties(): void
    {
        $schema = RestSchemaBuilder::build(SchemaTestDto::class);

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('email', $schema['properties']);
    }

    public function testRequiredListBuiltFromRequiredRuleAndNonDefault(): void
    {
        $schema = RestSchemaBuilder::build(SchemaTestDto::class);

        self::assertSame(['email', 'name'], $schema['required']);
    }

    public function testEmailRuleMapsToFormatEmail(): void
    {
        $props = RestSchemaBuilder::build(SchemaTestDto::class)['properties'];

        self::assertSame('email', $props['email']['format']);
    }

    public function testUrlRuleMapsToFormatUri(): void
    {
        $props = RestSchemaBuilder::build(SchemaTestDto::class)['properties'];

        self::assertSame('uri', $props['website']['format']);
    }

    public function testUuidRuleMapsToFormatUuid(): void
    {
        $props = RestSchemaBuilder::build(SchemaTestDto::class)['properties'];

        self::assertSame('uuid', $props['externalId']['format']);
    }

    public function testMinMaxLengthMap(): void
    {
        $name = RestSchemaBuilder::build(SchemaTestDto::class)['properties']['name'];

        self::assertSame(2, $name['minLength']);
        self::assertSame(50, $name['maxLength']);
    }

    public function testMinMaxNumericMap(): void
    {
        $age = RestSchemaBuilder::build(SchemaTestDto::class)['properties']['age'];

        self::assertSame(0, $age['minimum']);
        self::assertSame(150, $age['maximum']);
        self::assertSame('integer', $age['type']);
    }

    public function testOneOfMapsToEnum(): void
    {
        $role = RestSchemaBuilder::build(SchemaTestDto::class)['properties']['role'];

        self::assertSame(['admin', 'editor', 'subscriber'], $role['enum']);
    }

    public function testRegexMapsToPatternWithoutDelimiters(): void
    {
        $sku = RestSchemaBuilder::build(SchemaTestDto::class)['properties']['sku'];

        self::assertSame('^[A-Z]{3}-\d+$', $sku['pattern']);
    }

    public function testNullableFieldGetsUnionType(): void
    {
        $website = RestSchemaBuilder::build(SchemaTestDto::class)['properties']['website'];

        self::assertSame(['string', 'null'], $website['type']);
    }

    public function testDateTimeMapsToDateTimeFormat(): void
    {
        $props = RestSchemaBuilder::build(PostBackedDto::class)['properties'];

        self::assertSame('string', $props['publishedAt']['type']);
        self::assertSame('date-time', $props['publishedAt']['format']);
    }

    public function testNestedDataObjectRecursesToObject(): void
    {
        $props = RestSchemaBuilder::build(ProfileDto::class)['properties'];

        self::assertSame('object', $props['address']['type']);
        self::assertArrayHasKey('city', $props['address']['properties']);
    }

    public function testBackedEnumProducesEnumArrayAndStringType(): void
    {
        $props = RestSchemaBuilder::build(ProfileDto::class)['properties'];

        self::assertSame('string', $props['role']['type']);
        self::assertSame(['admin', 'editor', 'subscriber'], $props['role']['enum']);
    }
}
