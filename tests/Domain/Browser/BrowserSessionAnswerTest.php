<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Repo\Argus\Sessions\ArgusBrowserSessionAnswer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** How the renderer's answers are read (the contract's response shapes) */
class BrowserSessionAnswerTest extends TestCase
{
    public function testANavigationAnswerIsRead(): void
    {
        $browserSessionAnswer = $this->readData(BrowserRendererContract::SESSION_ACTION_NAVIGATE, '{"reason":"OK","sessionKey":"0123456789abcdef0123456789abcdef","tab":"t1","finalUrl":"https://www.example.com/","statusCode":200,"title":"Example","redirects":["http://example.com/","https://www.example.com/"],"consent":{"cmp":"usercentrics-api","action":"REJECTED"},"snapshot":{"mode":"outline","text":"- banner\n- main: 3 headings"}}');
        self::assertTrue($browserSessionAnswer->isOk());
        self::assertSame('0123456789abcdef0123456789abcdef', $browserSessionAnswer->sessionKey);
        self::assertSame('https://www.example.com/', $browserSessionAnswer->finalUrl);
        self::assertSame(['http://example.com/', 'https://www.example.com/'], $browserSessionAnswer->redirects);
        self::assertSame('REJECTED', $browserSessionAnswer->consentAction);
        self::assertSame("- banner\n- main: 3 headings", $browserSessionAnswer->snapshotText);
    }

    public function testAStoppedSubmissionCarriesItsReleaseToken(): void
    {
        $browserSessionAnswer = $this->readData(BrowserRendererContract::SESSION_ACTION_ACT, '{"reason":"CONSENT_REQUIRED","url":"https://example.com/contact","navigated":false,"submissions":[{"method":"POST","target":"https://example.com/send","fields":[{"name":"email","value":"a@example.com","typed":true},{"name":"topic","value":"x","typed":false}],"releaseToken":"rt-9","expiresAt":"2026-11-23T12:00:00Z"}]}');
        self::assertFalse($browserSessionAnswer->isOk());
        $browserSubmission = $browserSessionAnswer->submissions?->first();
        self::assertNotNull($browserSubmission);
        self::assertSame('rt-9', $browserSubmission->releaseToken);
        self::assertSame(2, $browserSubmission->fields?->count());
        self::assertStringContainsString('POST to https://example.com/send', $browserSubmission->buildDescription());
        self::assertStringContainsString('"a@example.com" (typed in this session)', $browserSubmission->buildDescription());
        self::assertStringNotContainsString('rt-9', $browserSubmission->buildConsentKey(), 'the consent binds to the data, never the token');
    }

    public function testScreenshotTabsDesignAndAssetsAreRead(): void
    {
        $screenshotAnswer = $this->readData(BrowserRendererContract::SESSION_ACTION_SCREENSHOT, '{"reason":"OK","content":"iVBORw0KGgo=","format":"png","width":1280,"height":4000,"truncated":true}');
        self::assertSame('iVBORw0KGgo=', $screenshotAnswer->imageContent);
        self::assertSame(4000, $screenshotAnswer->imageHeight);
        self::assertTrue($screenshotAnswer->imageTruncated);

        $tabsAnswer = $this->readData(BrowserRendererContract::SESSION_ACTION_TABS, '{"reason":"OK","tabs":[{"tab":"t1","url":"https://example.com/","title":"A","active":false},{"tab":"t2","url":"https://example.com/b","title":"B","active":true}]}');
        self::assertSame(2, $tabsAnswer->tabs?->count());

        $designAnswer = $this->readData(BrowserRendererContract::SESSION_ACTION_ANALYSE, '{"reason":"OK","tokens":{"palette":[{"role":"primary","color":"#005e3f"}]},"summary":"Primary #005e3f"}');
        self::assertStringContainsString('"#005e3f"', (string)$designAnswer->designTokensJson);
        self::assertSame('Primary #005e3f', $designAnswer->analysisSummary);

        $assetsAnswer = $this->readData(BrowserRendererContract::SESSION_ACTION_ANALYSE, '{"reason":"OK","files":[{"kind":"logo","name":"logo.svg","mimeType":"image/png","sourceUrl":"https://example.com/logo.svg","size":12,"content":"PHN2Zz48L3N2Zz4="}]}');
        self::assertSame('image/png', $assetsAnswer->assetFiles?->first()?->mimeType, 'kept as the claim; the type that counts is read from the bytes');
    }

    public function testABusyRendererIsAFaultWithItsWait(): void
    {
        $argusBrowserSessionAnswer = new ArgusBrowserSessionAnswer();
        $argusApiOperation = (new ReflectionClass(ArgusApiOperation::class))->newInstanceWithoutConstructor();
        $argusApiOperation->responseStatusCode = 429;
        $argusApiOperation->responseHeaders = ['Retry-After' => ['30']];
        $argusApiOperation->rejectionReason = null;
        $callResponseData = (object)['status' => 429, 'error' => 'renderer busy'];
        $argusBrowserSessionAnswer->handleLoadResponse($callResponseData, $argusApiOperation);
        self::assertTrue($argusBrowserSessionAnswer->isRendererFault());
        self::assertSame(429, $argusBrowserSessionAnswer->httpStatusCode);
        self::assertSame(30, $argusBrowserSessionAnswer->retryAfterSeconds);
        self::assertSame('renderer busy', $argusBrowserSessionAnswer->error);
    }

    public function testAPageOutcomeComesInTheEnvelope(): void
    {
        $argusBrowserSessionAnswer = new ArgusBrowserSessionAnswer();
        $argusApiOperation = (new ReflectionClass(ArgusApiOperation::class))->newInstanceWithoutConstructor();
        $argusApiOperation->responseStatusCode = 200;
        $argusApiOperation->responseHeaders = [];
        $callResponseData = json_decode('{"status":200,"data":{"reason":"SESSION_GONE"}}');
        $argusBrowserSessionAnswer->handleLoadResponse($callResponseData, $argusApiOperation);
        self::assertFalse($argusBrowserSessionAnswer->isRendererFault());
        self::assertSame(BrowserRendererContract::REASON_SESSION_GONE, $argusBrowserSessionAnswer->reason);
    }

    protected function readData(string $action, string $dataJson): BrowserSessionAnswer
    {
        $browserSessionAnswer = new BrowserSessionAnswer();
        $browserSessionAnswer->action = $action;
        $browserSessionAnswer->httpStatusCode = 200;
        $browserSessionAnswer->fillFromRendererData(json_decode($dataJson));
        return $browserSessionAnswer;
    }
}
