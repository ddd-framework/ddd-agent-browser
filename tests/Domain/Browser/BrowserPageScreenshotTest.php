<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\AI\Entities\Browser\Agents\BrowserPageScreenshotPart;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Tests\Browser\Fakes\ScriptedPageScreenshotsBrowserAgentPagesService;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The page tools on the renderer's stateless route: the request body, the answer, the parts of a page */
class BrowserPageScreenshotTest extends TestCase
{
    public function testTheRequestBodyIsTheRenderersCamelCaseContract(): void
    {
        $argusBrowserPageScreenshot = new ArgusBrowserPageScreenshot();
        $argusBrowserPageScreenshot->url = 'https://www.example.com/';
        $argusBrowserPageScreenshot->viewportWidth = 390;
        $argusBrowserPageScreenshot->viewportHeight = 8000;
        $argusBrowserPageScreenshot->isMobile = true;
        $rendererBody = $argusBrowserPageScreenshot->buildRendererBody();
        self::assertSame('https://www.example.com/', $rendererBody['url']);
        self::assertSame('mobile', $rendererBody['device']);
        self::assertSame(390, $rendererBody['viewportWidth']);
        self::assertSame(8000, $rendererBody['viewportHeight']);
        self::assertTrue($rendererBody['fullPage']);
        self::assertSame('png', $rendererBody['format']);
        self::assertFalse($rendererBody['omitBackground']);
        foreach (array_keys($rendererBody) as $bodyKey) {
            self::assertStringNotContainsString('_', $bodyKey, 'the renderer reads camelCase keys only');
        }
        self::assertSame('/webPageRenderer/screenshot', BrowserRendererContract::ROUTE_SCREENSHOT);
    }

    public function testTheCaptureIdentityHoldsTheViewportHeight(): void
    {
        $shortCapture = new ArgusBrowserPageScreenshot();
        $shortCapture->url = 'https://www.example.com/';
        $tallCapture = new ArgusBrowserPageScreenshot();
        $tallCapture->url = 'https://www.example.com/';
        $tallCapture->viewportHeight = BrowserPageScreenshotPart::TALL_VIEWPORT_HEIGHT_PX;
        self::assertNotSame($shortCapture->uniqueKey(), $tallCapture->uniqueKey());
    }

    public function testAFailedAnswerNamesItsReason(): void
    {
        $botChallengeAnswer = (object)['status' => 200, 'data' => (object)['reason' => 'BOT_CHALLENGE']];
        self::assertSame('BOT_CHALLENGE', ArgusBrowserPageScreenshot::findFailureReasonOfAnswer($botChallengeAnswer));
        $busyAnswer = (object)['status' => 429, 'error' => 'queue full'];
        self::assertSame(BrowserRendererContract::REASON_BUSY, ArgusBrowserPageScreenshot::findFailureReasonOfAnswer($busyAnswer));
        $timedOutCall = (new \ReflectionClass(ArgusApiOperation::class))->newInstanceWithoutConstructor();
        $timedOutCall->rejectionReason = 'Operation timed out after 40001 milliseconds';
        self::assertSame(BrowserRendererContract::REASON_TIMEOUT, ArgusBrowserPageScreenshot::findFailureReasonOfAnswer(null, $timedOutCall));
        self::assertSame(BrowserRendererContract::REASON_FETCH_FAILED, ArgusBrowserPageScreenshot::findFailureReasonOfAnswer(null));
        self::assertSame(BrowserRendererContract::REASON_TIMEOUT, ArgusBrowserPageScreenshot::findFailureReasonOfTransportError(new RuntimeException('cURL error 28: timeout')));
    }

    public function testAPrivateOrNonHttpUrlIsNeverRendered(): void
    {
        $browserAgentPagesService = new ScriptedPageScreenshotsBrowserAgentPagesService();
        self::assertSame(BrowserPageScreenshotPart::REASON_NOT_PUBLIC, $browserAgentPagesService->findScreenshotRefusalReasonOfHost('http://internal.test/'));
        $pageScreenshotPart = $browserAgentPagesService->capturePagePartForModel('http://internal.test/admin');
        self::assertSame(0, $pageScreenshotPart->pageHeightPx);
        self::assertSame(BrowserPageScreenshotPart::REASON_NOT_PUBLIC, $pageScreenshotPart->failureReason);
        self::assertSame([], $browserAgentPagesService->capturedViewports, 'nothing was sent');
    }

    public function testAFailedRenderAnswersItsReasonAndTheNextMove(): void
    {
        $browserAgentPagesService = new ScriptedPageScreenshotsBrowserAgentPagesService();
        $browserAgentPagesService->failureReason = BrowserPageScreenshotPart::REASON_BOT_CHALLENGE;
        $pageScreenshotPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/');
        self::assertSame(0, $pageScreenshotPart->pageHeightPx);
        self::assertSame(BrowserPageScreenshotPart::REASON_BOT_CHALLENGE, $pageScreenshotPart->failureReason);
        self::assertStringContainsString('getWebPageAsMarkdown', $pageScreenshotPart->buildFailureText());
    }

    public function testATallPageIsCutIntoPartsOfTwoSegments(): void
    {
        $this->skipWithoutImagick();
        $browserAgentPagesService = new ScriptedPageScreenshotsBrowserAgentPagesService();
        $browserAgentPagesService->base64PngByViewportHeight[BrowserPageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX] = $this->buildBase64Png(1024, 9000);
        $firstPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/');
        self::assertSame(9000, $firstPart->pageHeightPx);
        self::assertSame(3, $firstPart->getPartCount());
        self::assertCount(2, $firstPart->base64Images);
        self::assertStringContainsString('Other parts: part=2, part=3', $firstPart->toCoverageText());
        $lastPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/', 3);
        self::assertCount(1, $lastPart->base64Images, '8,000–9,000 px is one segment');
        $missingPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/', 4);
        self::assertSame([], $missingPart->base64Images);
        self::assertSame(['https://www.example.com/|1024x1400|d'], array_values(array_unique($browserAgentPagesService->capturedViewports)), 'a page taller than the window is not rendered again');
    }

    public function testAPageThatBlocksScrollingIsRenderedAgainInATallWindow(): void
    {
        $this->skipWithoutImagick();
        $browserAgentPagesService = new ScriptedPageScreenshotsBrowserAgentPagesService();
        // the full-page render stops at the window, its content runs to the bottom edge
        $browserAgentPagesService->base64PngByViewportHeight[BrowserPageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX] = $this->buildBase64Png(1024, BrowserPageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, true);
        $browserAgentPagesService->base64PngByViewportHeight[BrowserPageScreenshotPart::TALL_VIEWPORT_HEIGHT_PX] = $this->buildBase64Png(1024, 5000, true);
        $pageScreenshotPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/', 1, BrowserPageScreenshotPart::DEVICE_MOBILE);
        self::assertTrue($pageScreenshotPart->renderedInTallWindow);
        self::assertSame(5000, $pageScreenshotPart->pageHeightPx);
        self::assertSame(['https://www.example.com/|390x1400|m', 'https://www.example.com/|390x8000|m'], $browserAgentPagesService->capturedViewports);
    }

    protected function skipWithoutImagick(): void
    {
        if (!class_exists(Imagick::class)) {
            self::markTestSkipped('Imagick is needed to cut a capture');
        }
    }

    /** A PNG of the given size; `$stripedToTheBottom` draws content down to the last row (no empty bottom) */
    protected function buildBase64Png(int $width, int $height, bool $stripedToTheBottom = false): string
    {
        $imagick = new Imagick();
        $imagick->newImage($width, $height, new ImagickPixel('white'));
        if ($stripedToTheBottom) {
            $stripeDraw = new \ImagickDraw();
            for ($stripeY = 0; $stripeY < $height; $stripeY += 20) {
                $stripeDraw->setFillColor(new ImagickPixel(($stripeY / 20) % 2 === 0 ? 'black' : 'red'));
                $stripeDraw->rectangle(0, $stripeY, $width - 1, min($height - 1, $stripeY + 9));
            }
            $imagick->drawImage($stripeDraw);
        }
        $imagick->setImageFormat('PNG');
        return base64_encode($imagick->getImageBlob());
    }
}
