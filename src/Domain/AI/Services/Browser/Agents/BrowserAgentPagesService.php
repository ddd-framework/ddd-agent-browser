<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser\Agents;

use DDD\Domain\AI\Entities\Browser\Agents\BrowserPageScreenshotPart;
use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\AI\Exceptions\Conversations\AgentToolExecutionTimeoutException;
use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Infrastructure\Http\PublicUrlGuard;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use Throwable;

/**
 * The page tools of the skill `Agent.Web.Pages` on the page screenshot call ({@see ArgusBrowserPageScreenshot}; by
 * default the renderer's stateless route, an application's same-path subclass otherwise): a page rendered full height and cut
 * into the parts a vision model reads ({@see capturePagePartForModel()}). A URL whose host is not public is never sent;
 * the renderer checks every request and redirect hop again.
 */
class BrowserAgentPagesService extends Service
{
    /** @var string[] Host class per host, kept for the process (a resolver failure is not kept) */
    protected static array $hostClassByHost = [];

    /**
     * Whether the page tools can render: {@see BrowserRendererService::isPageRenderingAvailable()} (by default a
     * stateless renderer endpoint is configured; a same-path subclass of the screenshot call decides otherwise).
     */
    public function isPageRenderingAvailable(): bool
    {
        return $this->getBrowserRendererService()->isPageRenderingAvailable();
    }

    /**
     * ONE capture of `$url` through the renderer, on a viewport of `$viewportWidth` × `$viewportHeight`, the full page.
     * `$bypassCache` renders afresh. An empty {@see BrowserPageScreenshot} with its reason when nothing was captured.
     */
    public function capturePageScreenshot(string $url, int $viewportWidth, int $viewportHeight, bool $isMobile, bool $bypassCache = false): BrowserPageScreenshot
    {
        $pageScreenshot = BrowserPageScreenshot::newInstance();
        $argusBrowserPageScreenshot = $this->getBrowserRendererService()->createPageScreenshotCall();
        if ($argusBrowserPageScreenshot === null) {
            $pageScreenshot->failureReason = BrowserPageScreenshotPart::REASON_CAPABILITY_OFF;
            return $pageScreenshot;
        }
        $argusBrowserPageScreenshot->url = $url;
        $argusBrowserPageScreenshot->viewportWidth = $viewportWidth;
        $argusBrowserPageScreenshot->viewportHeight = $viewportHeight;
        $argusBrowserPageScreenshot->fullPage = true;
        $argusBrowserPageScreenshot->isMobile = $isMobile;
        try {
            $argusBrowserPageScreenshot->argusLoad(useArgusEntityCache: !$bypassCache, useApiACallCache: !$bypassCache);
        } catch (AgentToolExecutionTimeoutException $agentToolExecutionTimeoutException) {
            throw $agentToolExecutionTimeoutException;
        } catch (Throwable $throwable) {
            // a connection error or the client's own timeout is a failed render with its reason, not an internal error
            // called on the loader's class: an application's subclass maps its own transport errors
            $pageScreenshot->failureReason = $argusBrowserPageScreenshot::findFailureReasonOfTransportError($throwable);
            return $pageScreenshot;
        }
        $pageScreenshot->content = ($argusBrowserPageScreenshot->content ?? '') !== '' ? $argusBrowserPageScreenshot->content : null;
        $pageScreenshot->failureReason = $pageScreenshot->content === null
            ? ($argusBrowserPageScreenshot->failureReason ?? BrowserPageScreenshotPart::REASON_FETCH_FAILED)
            : null;
        $pageScreenshot->finalUrl = $argusBrowserPageScreenshot->finalUrl;
        $pageScreenshot->title = $argusBrowserPageScreenshot->title;
        $pageScreenshot->pageHttpStatusCode = $argusBrowserPageScreenshot->pageHttpStatusCode;
        $pageScreenshot->consentPlatform = $argusBrowserPageScreenshot->consentPlatform;
        $pageScreenshot->consentAction = $argusBrowserPageScreenshot->consentAction;
        return $this->refuseCaptureThatIsNotPng($pageScreenshot);
    }

    /**
     * The capture as the page tools may use it: an image that is not a PNG by its magic bytes (whatever call produced
     * it) is refused as {@see BrowserPageScreenshot::REASON_UNSUPPORTED_TYPE} before a decoder sees it.
     */
    protected function refuseCaptureThatIsNotPng(BrowserPageScreenshot $pageScreenshot): BrowserPageScreenshot
    {
        $imageBlob = $pageScreenshot->getImageBlob();
        if ($pageScreenshot->content !== null && ($imageBlob === null || RasterImageDecoder::detectFormat($imageBlob) !== RasterImageDecoder::FORMAT_PNG)) {
            $pageScreenshot->content = null;
            $pageScreenshot->failureReason = BrowserPageScreenshot::REASON_UNSUPPORTED_TYPE;
        }
        return $pageScreenshot;
    }

    /**
     * ONE part of `$url` for a vision model: the page rendered full height on the device's viewport, then the window of
     * part `$partNumber` cut into {@see BrowserPageScreenshotPart::SEGMENTS_PER_PART} slices of
     * {@see BrowserPageScreenshotPart::SLICE_HEIGHT_PX} px, with the page height and part count of the coverage line.
     * Every part of one page reuses the same cached capture unless `$bypassCache` asks for a fresh render.
     *
     * A full-page render exactly one window tall is either a short page (its bottom is empty background) or a page that
     * blocks scrolling (its content runs to the window edge): only the second is rendered again in a
     * {@see BrowserPageScreenshotPart::TALL_VIEWPORT_HEIGHT_PX} window, the empty bottom cut off, and kept when it is
     * taller than one window. A URL that is not public, no renderer or nothing captured → page height 0 and the reason
     * on {@see BrowserPageScreenshotPart::$failureReason}; a part past the last one comes back without images.
     */
    public function capturePagePartForModel(string $url, int $partNumber = 1, string $device = BrowserPageScreenshotPart::DEVICE_DESKTOP, bool $bypassCache = false): BrowserPageScreenshotPart
    {
        $isMobile = $device === BrowserPageScreenshotPart::DEVICE_MOBILE;
        $pageScreenshotPart = BrowserPageScreenshotPart::newInstance();
        $pageScreenshotPart->url = trim($url);
        $pageScreenshotPart->device = $isMobile ? BrowserPageScreenshotPart::DEVICE_MOBILE : BrowserPageScreenshotPart::DEVICE_DESKTOP;
        $pageScreenshotPart->partNumber = max(1, $partNumber);
        if (!$this->isPageRenderingAvailable()) {
            $pageScreenshotPart->failureReason = BrowserPageScreenshotPart::REASON_CAPABILITY_OFF;
            return $pageScreenshotPart;
        }
        $refusalReason = $this->findScreenshotRefusalReasonOfHost($pageScreenshotPart->url);
        if ($refusalReason !== null) {
            $pageScreenshotPart->failureReason = $refusalReason;
            return $pageScreenshotPart;
        }
        $viewportWidth = $isMobile ? BrowserPageScreenshotPart::MOBILE_VIEWPORT_WIDTH_PX : BrowserPageScreenshotPart::DESKTOP_VIEWPORT_WIDTH_PX;
        $pageScreenshot = $this->capturePageScreenshot($pageScreenshotPart->url, $viewportWidth, BrowserPageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, $isMobile, $bypassCache);
        // without Imagick the bottom cannot be judged: the first render stays
        if (BrowserPageScreenshot::isImagickAvailable() && $pageScreenshot->getPixelHeight() === BrowserPageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX && !$pageScreenshot->hasEmptyBottom()) {
            $tallPageScreenshot = $this->capturePageScreenshot($pageScreenshotPart->url, $viewportWidth, BrowserPageScreenshotPart::TALL_VIEWPORT_HEIGHT_PX, $isMobile, $bypassCache)
                ->withEmptyBottomCutOff();
            if ($tallPageScreenshot->getPixelHeight() > BrowserPageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX) {
                $pageScreenshot = $tallPageScreenshot;
                $pageScreenshotPart->renderedInTallWindow = true;
            }
        }
        $pageScreenshotPart->pageWidthPx = $pageScreenshot->getPixelWidth();
        $pageScreenshotPart->pageHeightPx = $pageScreenshot->getPixelHeight();
        if ($pageScreenshotPart->pageHeightPx <= 0) {
            $pageScreenshotPart->failureReason = $pageScreenshot->failureReason;
        }
        if ($pageScreenshotPart->pageHeightPx > 0 && $pageScreenshotPart->partNumber <= $pageScreenshotPart->getPartCount()) {
            $pageScreenshotPart->base64Images = $pageScreenshot->buildSegmentWindowBase64(
                BrowserPageScreenshotPart::SLICE_HEIGHT_PX,
                ($pageScreenshotPart->partNumber - 1) * BrowserPageScreenshotPart::SEGMENTS_PER_PART,
                BrowserPageScreenshotPart::SEGMENTS_PER_PART
            );
        }
        return $pageScreenshotPart;
    }

    /**
     * Why `$url` is not rendered, decided before the render from its scheme and host: not http(s) or a private or
     * reserved address is NOT_PUBLIC, a host without a DNS record UNRESOLVABLE (a typo). When the resolver gives no
     * answer the guard's own check decides. Null = render.
     */
    public function findScreenshotRefusalReasonOfHost(string $url): ?string
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return BrowserPageScreenshotPart::REASON_NOT_PUBLIC;
        }
        $hostClass = $this->classifyHostMemoized($url);
        if ($hostClass === PublicUrlGuard::HOST_UNRESOLVABLE) {
            return BrowserPageScreenshotPart::REASON_UNRESOLVABLE;
        }
        if ($hostClass === PublicUrlGuard::HOST_PRIVATE) {
            return BrowserPageScreenshotPart::REASON_NOT_PUBLIC;
        }
        if ($hostClass === PublicUrlGuard::HOST_UNKNOWN && !PublicUrlGuard::resolvedHostsAreAllPublic($url)) {
            return BrowserPageScreenshotPart::REASON_NOT_PUBLIC;
        }
        return null;
    }

    /** {@see PublicUrlGuard::classifyHost()} once per host and process; a resolver failure (UNKNOWN) is not kept. */
    protected function classifyHostMemoized(string $url): string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return PublicUrlGuard::classifyHost($url);
        }
        if (isset(static::$hostClassByHost[$host])) {
            return static::$hostClassByHost[$host];
        }
        $hostClass = PublicUrlGuard::classifyHost($url);
        if ($hostClass !== PublicUrlGuard::HOST_UNKNOWN) {
            static::$hostClassByHost[$host] = $hostClass;
        }
        return $hostClass;
    }

    protected function getBrowserRendererService(): BrowserRendererService
    {
        /** @var BrowserRendererService $browserRendererService */
        $browserRendererService = DDDService::instance()->getService(BrowserRendererService::class);
        return $browserRendererService;
    }
}
