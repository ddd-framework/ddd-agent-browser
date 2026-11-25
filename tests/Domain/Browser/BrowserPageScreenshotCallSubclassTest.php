<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\AI\Entities\Browser\Agents\BrowserPageScreenshotPart;
use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererDeploymentCheck;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Tests\Browser\Fakes\PublicHostsBrowserAgentPagesService;
use DDD\Tests\Browser\Fakes\ScriptedSnakeCaseApiBrowserPageScreenshot;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

/**
 * Contract: an application's same-path subclass of the screenshot call (resolved as `newInstance()` resolves an `App\`
 * class) speaks another API — endpoint, internal auth, snake_case body, `{status, content}` answer, its own
 * availability — and the page tools work on it end to end.
 */
class BrowserPageScreenshotCallSubclassTest extends TestCase
{
    protected function setUp(): void
    {
        ScriptedSnakeCaseApiBrowserPageScreenshot::resetScript();
        // what DDDService finds for an application class at the same path as the package's class
        StaticRegistry::$frameworkToAppClassNames[ArgusBrowserPageScreenshot::class] = ScriptedSnakeCaseApiBrowserPageScreenshot::class;
    }

    protected function tearDown(): void
    {
        unset(StaticRegistry::$frameworkToAppClassNames[ArgusBrowserPageScreenshot::class]);
        ScriptedSnakeCaseApiBrowserPageScreenshot::resetScript();
    }

    protected function buildBase64Png(int $width, int $height): string
    {
        $imagick = new Imagick();
        $imagick->newImage($width, $height, new ImagickPixel('white'));
        $imagick->setImageFormat('PNG');
        return base64_encode($imagick->getImageBlob());
    }

    public function testTheRendererServiceBuildsTheApplicationsSubclass(): void
    {
        $browserRendererService = new BrowserRendererService();
        self::assertFalse($browserRendererService->isPageRenderingAvailable(), 'the subclass decides: its endpoint is not configured');
        self::assertNull($browserRendererService->createPageScreenshotCall());
        ScriptedSnakeCaseApiBrowserPageScreenshot::$configuredEndpointPath = '/screenshots/v1/capture';
        self::assertTrue($browserRendererService->isPageRenderingAvailable(), 'available without any renderer endpoint');
        self::assertInstanceOf(ScriptedSnakeCaseApiBrowserPageScreenshot::class, $browserRendererService->createPageScreenshotCall());
    }

    public function testTheSubclassWorksEndToEndThroughThePageTools(): void
    {
        if (!class_exists(Imagick::class)) {
            self::markTestSkipped('needs Imagick');
        }
        ScriptedSnakeCaseApiBrowserPageScreenshot::$configuredEndpointPath = '/screenshots/v1/capture';
        ScriptedSnakeCaseApiBrowserPageScreenshot::$scriptedAnswer = (object)['status' => 'success', 'content' => $this->buildBase64Png(390, 5000)];
        $browserAgentPagesService = new PublicHostsBrowserAgentPagesService();
        self::assertTrue($browserAgentPagesService->isPageRenderingAvailable());
        $pageScreenshotPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/', 1, BrowserPageScreenshotPart::DEVICE_MOBILE);
        self::assertNull($pageScreenshotPart->failureReason);
        self::assertSame(5000, $pageScreenshotPart->pageHeightPx, 'the page height comes from the image');
        self::assertSame(2, $pageScreenshotPart->getPartCount());
        self::assertCount(2, $pageScreenshotPart->base64Images);
        self::assertSame(['POST:/screenshots/v1/capture'], ScriptedSnakeCaseApiBrowserPageScreenshot::$calledEndpoints);
        $sentPayload = ScriptedSnakeCaseApiBrowserPageScreenshot::$sentPayloads[0];
        self::assertArrayNotHasKey(ArgusApiOperation::EXTERNAL_CALL, $sentPayload, 'an internal call with the application\'s own auth');
        self::assertArrayNotHasKey('headers', $sentPayload);
        $requestBody = $sentPayload['body'];
        self::assertSame('https://www.example.com/', $requestBody['url']);
        self::assertSame('base64', $requestBody['responseType']);
        self::assertSame(BrowserPageScreenshotPart::MOBILE_VIEWPORT_WIDTH_PX, $requestBody['viewport_width']);
        self::assertSame(BrowserPageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, $requestBody['viewport_height']);
        self::assertTrue($requestBody['is_mobile']);
        self::assertTrue($requestBody['force_full_page']);
        self::assertSame('png', $requestBody['type']);
        foreach (['width', 'height', 'density', 'javascript_enabled', 'redirect_enabled', 'dom_timeout', 'min_wait', 'emulate_full_page', 'wait_until', 'omit_background'] as $fieldName) {
            self::assertArrayHasKey($fieldName, $requestBody);
        }
        $preparedSettings = ScriptedSnakeCaseApiBrowserPageScreenshot::$preparedArgusSettings[0];
        self::assertSame(ArgusCache::CACHELEVEL_MEMORY, $preparedSettings->argusLoad->cacheLevel, 'the package cache without a repeated #[ArgusLoad]');
        self::assertSame(ArgusCache::CACHE_TTL_TEN_MINUTES, $preparedSettings->argusLoad->cacheTtl);
        self::assertSame((float)BrowserRendererService::DEFAULT_SCREENSHOT_TIMEOUT_SECONDS, $preparedSettings->timeout);
    }

    public function testTheHealthCheckNamesTheApplicationsCallWithoutAFault(): void
    {
        $browserRendererService = new BrowserRendererService();
        ScriptedSnakeCaseApiBrowserPageScreenshot::$configuredEndpointPath = '/screenshots/v1/capture';
        $browserRendererDeploymentChecks = $browserRendererService->checkDeployments();
        self::assertTrue($browserRendererDeploymentChecks->areAllHealthy(), 'no renderer configured, the application call is not a fault');
        $pageScreenshotCallCheck = $browserRendererDeploymentChecks->getByUniqueKey(BrowserRendererDeploymentCheck::uniqueKeyStatic(BrowserRendererService::DEPLOYMENT_PAGE_SCREENSHOTS));
        self::assertNotNull($pageScreenshotCallCheck);
        self::assertSame('POST:/screenshots/v1/capture', $pageScreenshotCallCheck->baseUrl);
        self::assertStringContainsString('not probed', (string)$pageScreenshotCallCheck->note);
        self::assertSame([], ScriptedSnakeCaseApiBrowserPageScreenshot::$calledEndpoints, 'the check sends nothing');
        ScriptedSnakeCaseApiBrowserPageScreenshot::$configuredEndpointPath = null;
        self::assertFalse($browserRendererService->checkDeployments()->areAllHealthy(), 'an application call that is not available is a fault');
    }

    public function testWithoutItsConfigurationThePageToolsAreOffAndNothingIsSent(): void
    {
        $browserAgentPagesService = new PublicHostsBrowserAgentPagesService();
        self::assertFalse($browserAgentPagesService->isPageRenderingAvailable());
        $pageScreenshotPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/');
        self::assertSame(BrowserPageScreenshotPart::REASON_CAPABILITY_OFF, $pageScreenshotPart->failureReason);
        self::assertSame([], ScriptedSnakeCaseApiBrowserPageScreenshot::$calledEndpoints);
    }

    public function testTheSubclassesFailureReasonPassesThroughToTheTool(): void
    {
        ScriptedSnakeCaseApiBrowserPageScreenshot::$configuredEndpointPath = '/screenshots/v1/capture';
        ScriptedSnakeCaseApiBrowserPageScreenshot::$scriptedAnswer = (object)['status' => 'error', 'error' => 'quota_exceeded'];
        $browserAgentPagesService = new PublicHostsBrowserAgentPagesService();
        $pageScreenshotPart = $browserAgentPagesService->capturePagePartForModel('https://www.example.com/');
        self::assertSame(0, $pageScreenshotPart->pageHeightPx);
        self::assertSame(BrowserPageScreenshot::REASON_BUSY, $pageScreenshotPart->failureReason, 'the subclass maps onto the package\'s reasons');
        ScriptedSnakeCaseApiBrowserPageScreenshot::$scriptedAnswer = (object)['status' => 'error', 'error' => 'bot_challenge'];
        $botChallengePart = $browserAgentPagesService->capturePagePartForModel('https://www.example.org/');
        self::assertSame(BrowserPageScreenshotPart::REASON_BOT_CHALLENGE, $botChallengePart->failureReason);
    }

    public function testATransportErrorIsMappedByTheSubclass(): void
    {
        ScriptedSnakeCaseApiBrowserPageScreenshot::$configuredEndpointPath = '/screenshots/v1/capture';
        ScriptedSnakeCaseApiBrowserPageScreenshot::$throwOnLoad = true;
        $browserAgentPagesService = new PublicHostsBrowserAgentPagesService();
        $pageScreenshot = $browserAgentPagesService->capturePageScreenshot('https://www.example.com/', 1024, 1400, false);
        self::assertSame(BrowserPageScreenshot::REASON_BLOCKED, $pageScreenshot->failureReason, 'findFailureReasonOfTransportError() is called on the loader\'s class');
    }

    public function testAnImageThatIsNoPngIsRefusedBeforeTheDecoder(): void
    {
        ScriptedSnakeCaseApiBrowserPageScreenshot::$configuredEndpointPath = '/screenshots/v1/capture';
        ScriptedSnakeCaseApiBrowserPageScreenshot::$scriptedAnswer = (object)['status' => 'success', 'content' => base64_encode("\xFF\xD8\xFF\xE0" . str_repeat('x', 64))];
        $browserAgentPagesService = new PublicHostsBrowserAgentPagesService();
        $pageScreenshot = $browserAgentPagesService->capturePageScreenshot('https://www.example.com/', 1024, 1400, false);
        self::assertNull($pageScreenshot->content);
        self::assertSame(BrowserPageScreenshot::REASON_UNSUPPORTED_TYPE, $pageScreenshot->failureReason);
    }
}
