<?php

declare(strict_types=1);

namespace BetterData\Tests\Unit;

use BetterData\Presenter\Formatter\I18nFormatter;
use BetterData\Presenter\PresentationContext;
use PHPUnit\Framework\TestCase;

final class I18nFormatterTest extends TestCase
{
    public function testFallbackReturnsOriginalTextWithoutWp(): void
    {
        $formatter = new I18nFormatter(PresentationContext::none());

        self::assertSame('Hello', $formatter->t('Hello'));
        self::assertSame('Verb', $formatter->x('Verb', 'grammatical'));
    }

    public function testPluralFallback(): void
    {
        $formatter = new I18nFormatter(PresentationContext::none());

        self::assertSame('1 item', $formatter->n('1 item', '%d items', 1));
        self::assertSame('%d items', $formatter->n('1 item', '%d items', 5));
    }

    public function testFSubstitutesPositionalArgs(): void
    {
        $formatter = new I18nFormatter(PresentationContext::none());

        self::assertSame('Hello, Alice!', $formatter->f('Hello, %s!', 'Alice'));
    }
}
