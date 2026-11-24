<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionRequest;
use DDD\Domain\AI\Services\Browser\Agents\BrowserAgentAnswersService;
use DDD\Domain\AI\Services\Browser\BrowserFileSinksService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Mcp\Client\Browser\BrowserSessionMCPPackage;
use DDD\Tests\Browser\Fakes\WiredBrowserAgentSessionsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** The renderer contract's second batch: every reason has a sentence, dropped requests, release use, refs, web folder */
class BrowserContractBatchTwoTest extends TestCase
{
    public function testEveryReasonBecomesASentenceWithTheNextMove(): void
    {
        $browserAgentAnswersService = new BrowserAgentAnswersService();
        foreach ((new ReflectionClass(BrowserRendererContract::class))->getConstants() as $constantName => $reason) {
            if (!str_starts_with($constantName, 'REASON_') || in_array($reason, ['OK', 'CLOSED'], true)) {
                continue;
            }
            $browserSessionAnswer = $this->answer((object)['reason' => $reason]);
            $refusalText = (string)$browserAgentAnswersService->buildRefusalText($browserSessionAnswer);
            self::assertNotSame('', $refusalText, $reason);
            self::assertStringNotContainsString('the action did not complete', $refusalText, $reason . ' has no own sentence');
        }
        self::assertStringContainsString('(idle)', (string)$browserAgentAnswersService->buildRefusalText($this->answer((object)['reason' => 'SESSION_GONE', 'cause' => 'idle'])));
    }

    public function testADroppedRequestIsNoConsentQuestion(): void
    {
        $wiredService = new WiredBrowserAgentSessionsService();
        $droppedAnswer = $this->answer(json_decode('{"reason":"CONSENT_REQUIRED","submissions":[{"kind":"dropped","method":"POST","target":"https://tracker.example.net/collect","fields":[],"releaseToken":null}]}'));
        $droppedAnswer->action = BrowserRendererContract::SESSION_ACTION_ACT;
        $wiredService->scriptedBrowserRendererService->scriptedAnswers = [$droppedAnswer];
        $actRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ACT, 'k');
        $actRequest->actAction = 'type';
        $actRequest->ref = 'e3';
        $answer = $wiredService->runActWithSubmissionRule(null, $this->session(), $actRequest, 'browserType');
        self::assertSame([], $wiredService->recordingOwnerConsentsService->askedTargetKeys, 'nothing to ask: no release exists');
        self::assertCount(1, $wiredService->scriptedBrowserRendererService->receivedRequests);
        $refusalText = (string)(new BrowserAgentAnswersService())->buildRefusalText($answer);
        self::assertStringContainsString('dropped for good', $refusalText);
        self::assertStringContainsString('do not ask the operator', $refusalText);
    }

    public function testActFieldsOfTheSecondBatchAreRead(): void
    {
        $browserSessionAnswer = $this->answer(json_decode('{"reason":"OK","url":"https://example.com/b","navigated":true,"newTabs":["t2"],"dialogs":[{"type":"alert","message":"hi"}],"releaseUsed":true}'));
        self::assertSame(['t2'], $browserSessionAnswer->newTabs);
        self::assertSame('t2', $browserSessionAnswer->newTab);
        self::assertTrue($browserSessionAnswer->releaseUsed);
        $actText = (new BrowserAgentAnswersService())->buildActText($browserSessionAnswer, 'Clicked e1');
        self::assertStringContainsString('opened tab t2', $actText);
        self::assertStringContainsString('the released request was sent', $actText);
    }

    public function testRefsMayCarryAFramePrefix(): void
    {
        foreach (['e12', 'f3e12'] as $validRef) {
            BrowserSessionMCPPackage::throwUnlessValidRef($validRef, 'browserClick', true);
            self::assertTrue(BrowserRendererContract::isValidRef($validRef));
        }
        foreach (['12', 'ref=e12', 'e12;drop', 'f3', ''] as $invalidRef) {
            self::assertFalse(BrowserRendererContract::isValidRef($invalidRef), $invalidRef);
        }
        $this->expectException(BadRequestException::class);
        BrowserSessionMCPPackage::throwUnlessValidRef('javascript:alert(1)', 'browserClick', true);
    }

    public function testFilesOfASiteGoToItsWebFolder(): void
    {
        $browserFileSinksService = new BrowserFileSinksService();
        self::assertSame('web/example.com', $browserFileSinksService->buildWebFolder('https://www.Example.com/start?x=1'));
        self::assertSame('web/unknown-site', $browserFileSinksService->buildWebFolder(null));
        foreach (['logo.svg', 'logo.png', 'logo-original.webp', 'stylesheet-01-main.css', 'screenshot-viewport.png', 'screenshot-page.jpg', 'font-Inter-Bold.woff2'] as $rendererName) {
            self::assertSame($rendererName, $browserFileSinksService->sanitizeFileName($rendererName, 'asset'), 'the renderer\'s names are kept');
        }
    }

    public function testTheDesignTokensPassThroughWithTheirStructure(): void
    {
        $tokensJson = '{"palette":{"primary":"#005e3f","link":"#0a58ca"},"scales":{"radius":[{"value":"4px","count":12}]},"logo":{"file":"logo.png","svgFile":"logo.svg","onDarkFile":null},"source":{"url":"https://example.com/","viewport":{"width":1280,"height":800}},"ratio":1.0}';
        $browserSessionAnswer = $this->answer((object)['reason' => 'OK', 'tokens' => json_decode($tokensJson), 'summary' => 'x']);
        self::assertEquals(json_decode($tokensJson, true), json_decode((string)$browserSessionAnswer->designTokensJson, true));
        self::assertStringContainsString('"ratio": 1.0', (string)$browserSessionAnswer->designTokensJson, 'numbers keep their form');
    }

    protected function answer(object $data): BrowserSessionAnswer
    {
        $browserSessionAnswer = new BrowserSessionAnswer();
        $browserSessionAnswer->httpStatusCode = 200;
        $browserSessionAnswer->fillFromRendererData($data);
        return $browserSessionAnswer;
    }

    protected function session(): BrowserSession
    {
        $browserSession = BrowserSession::newInstance();
        $browserSession->sessionKey = 'k';
        return $browserSession;
    }
}
