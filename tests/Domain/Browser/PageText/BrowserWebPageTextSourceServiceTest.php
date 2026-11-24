<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser\PageText;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Services\Browser\PageText\BrowserWebPageTextSourceService;
use DDD\Domain\AI\Services\WebSearch\WebPageTextSource;
use DDD\Infrastructure\Untrusted\GuardedHttpFetchResult;
use DDD\Modules\AgentBrowser\AgentBrowserModule;
use DDD\Tests\Browser\Fakes\ConfiguredBrowserWebPageTextSourceService;
use DDD\Tests\Browser\Fakes\ScriptedGuardedHttpFetchService;
use DDD\Tests\Browser\Fakes\ScriptedSessionBrowserRendererService;
use PHPUnit\Framework\TestCase;

class BrowserWebPageTextSourceServiceTest extends TestCase
{
    protected const string URL = 'https://www.example.com/page';

    protected function createRichHtml(): string
    {
        return '<html><head><title>Rich page</title></head><body><main><h1>Welcome</h1><p>'
            . str_repeat('This page has plenty of real text for a reader. ', 30)
            . '</p></main></body></html>';
    }

    protected function createBrowserAnswer(string $reason, array $fields = []): BrowserSessionAnswer
    {
        $browserSessionAnswer = new BrowserSessionAnswer();
        $browserSessionAnswer->httpStatusCode = 200;
        $browserSessionAnswer->reason = $reason;
        foreach ($fields as $fieldName => $value) {
            $browserSessionAnswer->$fieldName = $value;
        }
        return $browserSessionAnswer;
    }

    public function testTheSourceImplementsThePortAndRegistersOnlyBesideIt(): void
    {
        self::assertTrue(is_a(BrowserWebPageTextSourceService::class, WebPageTextSource::class, true));
        $requiredClasses = AgentBrowserModule::getNamespacesRequiringClasses();
        self::assertSame(WebPageTextSource::class, $requiredClasses['DDD\\Domain\\AI\\Services\\Browser\\PageText\\']);
    }

    public function testAvailableOnlyWhenEnabled(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        self::assertTrue($browserWebPageTextSourceService->isAvailable());
        $browserWebPageTextSourceService->pageTextSettings['enabled'] = false;
        self::assertFalse($browserWebPageTextSourceService->isAvailable());
    }

    public function testARichPageIsReadOverHttpWithoutTheBrowser(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        $browserWebPageTextSourceService->scriptedGuardedHttpFetchService->resultsByUrl[static::URL] = ScriptedGuardedHttpFetchService::createHtmlResult(static::URL, $this->createRichHtml());
        $webPageMarkdowns = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL]);
        $webPageMarkdown = $webPageMarkdowns->getElements()[0];
        self::assertTrue($webPageMarkdown->ok);
        self::assertSame('http', $webPageMarkdown->fetchedVia);
        self::assertSame('Rich page', $webPageMarkdown->title);
        self::assertStringStartsWith('# Welcome', (string)$webPageMarkdown->markdown);
        self::assertSame(static::URL, $webPageMarkdown->url);
        self::assertSame([], $browserWebPageTextSourceService->scriptedBrowserRendererService->receivedRequests);
    }

    public function testAThinPageIsReadAgainInTheBrowserAndTheSessionIsClosed(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        $browserWebPageTextSourceService->scriptedGuardedHttpFetchService->resultsByUrl[static::URL] = ScriptedGuardedHttpFetchService::createHtmlResult(static::URL, '<html><body><div id="root"></div><p>Loading</p></body></html>');
        /** @var ScriptedSessionBrowserRendererService $scriptedBrowserRendererService */
        $scriptedBrowserRendererService = $browserWebPageTextSourceService->scriptedBrowserRendererService;
        $renderedText = str_repeat('Rendered text of the application. ', 40);
        $scriptedBrowserRendererService->scriptedAnswers = [
            $this->createBrowserAnswer('OK', ['sessionKey' => str_repeat('a', 32), 'tab' => 't1', 'title' => 'App', 'finalUrl' => static::URL]),
            $this->createBrowserAnswer('OK', ['snapshotText' => $renderedText . "\n… 12 more lines (cursor=40)", 'nextCursor' => '40']),
            $this->createBrowserAnswer('OK', ['snapshotText' => 'The last part.', 'nextCursor' => null]),
            $this->createBrowserAnswer('CLOSED'),
        ];
        $webPageMarkdown = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL])->getElements()[0];
        self::assertTrue($webPageMarkdown->ok);
        self::assertSame('browser', $webPageMarkdown->fetchedVia);
        self::assertSame('App', $webPageMarkdown->title);
        self::assertStringNotContainsString('more lines', (string)$webPageMarkdown->markdown);
        self::assertStringEndsWith('The last part.', (string)$webPageMarkdown->markdown);
        $actions = array_map(static fn($request) => $request->action, $scriptedBrowserRendererService->receivedRequests);
        self::assertSame(['navigate', 'snapshot', 'snapshot', 'close'], $actions);
        self::assertSame('text', $scriptedBrowserRendererService->receivedRequests[1]->mode);
        self::assertSame(40, $scriptedBrowserRendererService->receivedRequests[2]->toRendererBody()['cursor']);
    }

    public function testRenderNeverKeepsTheThinHttpRead(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        $browserWebPageTextSourceService->pageTextSettings['render'] = 'never';
        $browserWebPageTextSourceService->scriptedGuardedHttpFetchService->resultsByUrl[static::URL] = ScriptedGuardedHttpFetchService::createHtmlResult(static::URL, '<html><body><p>Short text.</p></body></html>');
        $webPageMarkdown = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL])->getElements()[0];
        self::assertTrue($webPageMarkdown->ok);
        self::assertSame('http', $webPageMarkdown->fetchedVia);
        self::assertSame([], $browserWebPageTextSourceService->scriptedBrowserRendererService->receivedRequests);
    }

    public function testRenderAlwaysFallsBackToHttpWhenNoRendererIsConfigured(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        $browserWebPageTextSourceService->pageTextSettings['render'] = 'always';
        /** @var ScriptedSessionBrowserRendererService $scriptedBrowserRendererService */
        $scriptedBrowserRendererService = $browserWebPageTextSourceService->scriptedBrowserRendererService;
        $scriptedBrowserRendererService->sessionBaseUrl = null;
        $browserWebPageTextSourceService->scriptedGuardedHttpFetchService->resultsByUrl[static::URL] = ScriptedGuardedHttpFetchService::createHtmlResult(static::URL, $this->createRichHtml());
        $webPageMarkdown = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL])->getElements()[0];
        self::assertTrue($webPageMarkdown->ok);
        self::assertSame('http', $webPageMarkdown->fetchedVia);
    }

    public function testABusyRendererKeepsTheHttpRead(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        $browserWebPageTextSourceService->scriptedGuardedHttpFetchService->resultsByUrl[static::URL] = ScriptedGuardedHttpFetchService::createHtmlResult(static::URL, '<html><body><p>Short text.</p></body></html>');
        $busyAnswer = new BrowserSessionAnswer();
        $busyAnswer->httpStatusCode = 429;
        /** @var ScriptedSessionBrowserRendererService $scriptedBrowserRendererService */
        $scriptedBrowserRendererService = $browserWebPageTextSourceService->scriptedBrowserRendererService;
        $scriptedBrowserRendererService->scriptedAnswers = [$busyAnswer];
        $webPageMarkdown = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL])->getElements()[0];
        self::assertTrue($webPageMarkdown->ok);
        self::assertSame('http', $webPageMarkdown->fetchedVia);
        self::assertStringContainsString('Short text.', (string)$webPageMarkdown->markdown);
    }

    public function testAnHttpFailureIsReadInTheBrowserAndABotChallengeThereIsTheReason(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        /** @var ScriptedSessionBrowserRendererService $scriptedBrowserRendererService */
        $scriptedBrowserRendererService = $browserWebPageTextSourceService->scriptedBrowserRendererService;
        $scriptedBrowserRendererService->scriptedAnswers = [
            $this->createBrowserAnswer(BrowserRendererContract::REASON_BOT_CHALLENGE, ['sessionKey' => str_repeat('b', 32)]),
            $this->createBrowserAnswer('CLOSED'),
        ];
        $webPageMarkdown = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL])->getElements()[0];
        self::assertFalse($webPageMarkdown->ok);
        self::assertSame(WebPageMarkdown::REASON_BOT_CHALLENGE, $webPageMarkdown->reason);
        self::assertSame('close', end($scriptedBrowserRendererService->receivedRequests)->action);
    }

    public function testABlockedAddressIsNeverRetriedInTheBrowser(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        $blockedResult = new GuardedHttpFetchResult();
        $blockedResult->requestedUrl = static::URL;
        $blockedResult->finalUrl = static::URL;
        $blockedResult->refusalReason = GuardedHttpFetchResult::REASON_BLOCKED;
        $browserWebPageTextSourceService->scriptedGuardedHttpFetchService->resultsByUrl[static::URL] = $blockedResult;
        $webPageMarkdown = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL])->getElements()[0];
        self::assertFalse($webPageMarkdown->ok);
        self::assertSame(WebPageMarkdown::REASON_BLOCKED, $webPageMarkdown->reason);
        self::assertSame([], $browserWebPageTextSourceService->scriptedBrowserRendererService->receivedRequests);
    }

    public function testEveryUrlGetsOneElementInOrder(): void
    {
        $browserWebPageTextSourceService = new ConfiguredBrowserWebPageTextSourceService();
        $browserWebPageTextSourceService->pageTextSettings['render'] = 'never';
        $secondUrl = 'https://www.example.org/other';
        $browserWebPageTextSourceService->scriptedGuardedHttpFetchService->resultsByUrl[$secondUrl] = ScriptedGuardedHttpFetchService::createHtmlResult($secondUrl, $this->createRichHtml());
        $elements = $browserWebPageTextSourceService->getWebPagesAsMarkdown([static::URL, $secondUrl])->getElements();
        self::assertCount(2, $elements);
        self::assertSame(static::URL, $elements[0]->url);
        self::assertFalse($elements[0]->ok);
        self::assertSame($secondUrl, $elements[1]->url);
        self::assertTrue($elements[1]->ok);
    }

    public function testTheContinuationLineIsStripped(): void
    {
        self::assertSame("Line one\nLine two", BrowserWebPageTextSourceService::stripContinuationLine("Line one\nLine two\n… 5 more lines (cursor=12)"));
    }
}
