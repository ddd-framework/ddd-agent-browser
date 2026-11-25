<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use PHPUnit\Framework\TestCase;

/** The package's own screenshot call on the renderer: endpoint, external call with the token, the answer's page facts */
class BrowserPageScreenshotDefaultCallTest extends TestCase
{
    /** @var string A 1×1 PNG */
    public const string BASE64_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testTheRequestIsTheRenderersExternalCallWithTheCallerToken(): void
    {
        $argusBrowserPageScreenshot = new ArgusBrowserPageScreenshot();
        $argusBrowserPageScreenshot->prepareCall('https://renderer.example.test/', 'caller-token', 55);
        $argusBrowserPageScreenshot->url = 'https://www.example.com/';
        self::assertTrue($argusBrowserPageScreenshot->isRenderingAvailable());
        $argusSettings = $argusBrowserPageScreenshot->getArgusSettings();
        self::assertSame('POST:https://renderer.example.test/webPageRenderer/screenshot', $argusSettings->argusLoad->loadEndpoint);
        self::assertSame(55.0, $argusSettings->timeout, 'the configured timeout reaches the HTTP client');
        $loadPayload = (new \ReflectionMethod($argusBrowserPageScreenshot, 'getLoadPayload'))->invoke($argusBrowserPageScreenshot);
        self::assertTrue($loadPayload[ArgusApiOperation::EXTERNAL_CALL]);
        self::assertSame('caller-token', $loadPayload['headers'][BrowserRendererContract::TOKEN_HEADER]);
        self::assertSame('desktop', $loadPayload['body']['device']);
    }

    public function testASuccessfulAnswerCarriesTheImageAndThePageFacts(): void
    {
        $argusBrowserPageScreenshot = new ArgusBrowserPageScreenshot();
        $argusBrowserPageScreenshot->prepareCall('https://renderer.example.test', null, 40);
        $argusBrowserPageScreenshot->getArgusSettings()->argusLoad->cacheTtl = 0; // the suite has no cache backend
        $callResponseData = (object)['status' => 200, 'data' => (object)[
            'reason' => 'OK', 'content' => self::BASE64_PNG, 'finalUrl' => 'https://www.example.com/start', 'title' => 'Example',
            'statusCode' => 200, 'consent' => (object)['cmp' => 'examplecmp', 'action' => 'REJECTED'],
        ]];
        $argusApiOperation = null;
        $argusBrowserPageScreenshot->handleLoadResponse($callResponseData, $argusApiOperation);
        self::assertSame(self::BASE64_PNG, $argusBrowserPageScreenshot->content);
        self::assertNull($argusBrowserPageScreenshot->failureReason);
        self::assertSame('https://www.example.com/start', $argusBrowserPageScreenshot->finalUrl);
        self::assertSame('Example', $argusBrowserPageScreenshot->title);
        self::assertSame(200, $argusBrowserPageScreenshot->pageHttpStatusCode);
        self::assertSame('examplecmp', $argusBrowserPageScreenshot->consentPlatform);
        self::assertSame('REJECTED', $argusBrowserPageScreenshot->consentAction);
        $blockedAnswer = (object)['status' => 200, 'data' => (object)['reason' => 'BLOCKED']];
        $argusBrowserPageScreenshot->handleLoadResponse($blockedAnswer, $argusApiOperation);
        self::assertNull($argusBrowserPageScreenshot->content);
        self::assertSame(BrowserRendererContract::REASON_BLOCKED, $argusBrowserPageScreenshot->failureReason);
    }
}
