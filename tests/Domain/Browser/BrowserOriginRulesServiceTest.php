<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\Browser\Entities\Origins\BrowserOriginTexts;
use DDD\Domain\Browser\Services\BrowserOriginRulesService;
use PHPUnit\Framework\TestCase;

/** Which model-written URLs and texts run without the operator's yes */
class BrowserOriginRulesServiceTest extends TestCase
{
    public function testAUrlTheOperatorWroteRuns(): void
    {
        $browserOriginTexts = BrowserOriginTexts::fromTexts(['Look at https://www.example.com/pricing please'], []);
        self::assertTrue((new BrowserOriginRulesService())->isUrlOfKnownOrigin('https://www.example.com/pricing', $browserOriginTexts));
    }

    public function testTheStartPageOfAHostTheOperatorNamedRuns(): void
    {
        $browserOriginTexts = BrowserOriginTexts::fromTexts(['Build a deck in the style of example.com'], []);
        $browserOriginRulesService = new BrowserOriginRulesService();
        self::assertTrue($browserOriginRulesService->isUrlOfKnownOrigin('https://www.example.com/', $browserOriginTexts));
        self::assertTrue($browserOriginRulesService->isUrlOfKnownOrigin('https://example.com', $browserOriginTexts));
        self::assertFalse($browserOriginRulesService->isUrlOfKnownOrigin('https://example.com/careers', $browserOriginTexts), 'a deeper path of a named host needs a source');
        self::assertFalse($browserOriginRulesService->isUrlOfKnownOrigin('https://example.com/?q=secret', $browserOriginTexts), 'a query is no bare start page');
        self::assertFalse($browserOriginRulesService->isUrlOfKnownOrigin('https://notexample.com/', $browserOriginTexts));
    }

    public function testAUrlAResultShowedInFullRuns(): void
    {
        $browserOriginTexts = BrowserOriginTexts::fromTexts([], ['{"links":["https:\/\/shop.example.org\/item?id=7"]}', '<a href="https://a.example.net/x?a=1&amp;b=2">']);
        $browserOriginRulesService = new BrowserOriginRulesService();
        self::assertTrue($browserOriginRulesService->isUrlOfKnownOrigin('https://shop.example.org/item?id=7', $browserOriginTexts), 'JSON-escaped');
        self::assertTrue($browserOriginRulesService->isUrlOfKnownOrigin('https://a.example.net/x?a=1&b=2', $browserOriginTexts), 'HTML-escaped');
    }

    public function testAModelBuiltUrlNeedsTheOperator(): void
    {
        $browserOriginTexts = BrowserOriginTexts::fromTexts(['Check https://example.com/a'], ['see https://example.com/a']);
        $browserOriginRulesService = new BrowserOriginRulesService();
        self::assertFalse($browserOriginRulesService->isUrlOfKnownOrigin('https://collector.example.net/?d=customer-list', $browserOriginTexts));
        self::assertFalse($browserOriginRulesService->isUrlOfKnownOrigin('https://example.com/ab', $browserOriginTexts), 'a longer URL is not the shown one');
        self::assertFalse($browserOriginRulesService->isUrlOfKnownOrigin('https://example.com/a?leak=1', $browserOriginTexts), 'a query appended to a shown URL');
    }

    public function testATypedTextMustBeTheOperatorsOwnWords(): void
    {
        $browserOriginTexts = BrowserOriginTexts::fromTexts(['Search the site for "Bonusprogramm 2026"'], ['the customer is Jane Doe, IBAN DE00…']);
        $browserOriginRulesService = new BrowserOriginRulesService();
        self::assertTrue($browserOriginRulesService->isTextFromOperator('Bonusprogramm 2026', $browserOriginTexts));
        self::assertFalse($browserOriginRulesService->isTextFromOperator('Jane Doe', $browserOriginTexts), 'a result is no origin for typed text');
    }
}
