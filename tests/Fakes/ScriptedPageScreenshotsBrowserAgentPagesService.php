<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\AI\Services\Browser\Agents\BrowserAgentPagesService;

/** Page tools whose renderer is a script: each capture answers the PNG of its viewport height, every call is recorded */
class ScriptedPageScreenshotsBrowserAgentPagesService extends BrowserAgentPagesService
{
    /** @var string[] base64 PNG by viewport height; a missing height answers the failure reason */
    public array $base64PngByViewportHeight = [];

    public string $failureReason = 'FETCH_FAILED';

    /** @var string[] One line per capture: `<url>|<width>x<height>|<m or d>` */
    public array $capturedViewports = [];

    public function isPageRenderingAvailable(): bool
    {
        return true;
    }

    public function findScreenshotRefusalReasonOfHost(string $url): ?string
    {
        return str_contains($url, 'internal.test') ? parent::findScreenshotRefusalReasonOfHost('http://127.0.0.1/') : null;
    }

    public function capturePageScreenshot(string $url, int $viewportWidth, int $viewportHeight, bool $isMobile, bool $bypassCache = false): BrowserPageScreenshot
    {
        $this->capturedViewports[] = $url . '|' . $viewportWidth . 'x' . $viewportHeight . '|' . ($isMobile ? 'm' : 'd');
        $pageScreenshot = BrowserPageScreenshot::newInstance();
        if (isset($this->base64PngByViewportHeight[$viewportHeight])) {
            $pageScreenshot->content = $this->base64PngByViewportHeight[$viewportHeight];
        } else {
            $pageScreenshot->failureReason = $this->failureReason;
        }
        return $pageScreenshot;
    }
}
