<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Services\Browser\BrowserHtmlMarkdownService;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Domain\AI\Services\Browser\PageText\BrowserWebPageTextSourceService;
use DDD\Infrastructure\Services\GuardedHttpFetchService;

/** The page reader with its settings, its fetch and its renderer set by the test */
class ConfiguredBrowserWebPageTextSourceService extends BrowserWebPageTextSourceService
{
    /** @var array<string, mixed> Settings of AI.Browser.pageText by name */
    public array $pageTextSettings = ['enabled' => true, 'cacheTtlSeconds' => 0];

    public ScriptedGuardedHttpFetchService $scriptedGuardedHttpFetchService;

    public BrowserRendererService $scriptedBrowserRendererService;

    public function __construct()
    {
        $this->scriptedGuardedHttpFetchService = new ScriptedGuardedHttpFetchService();
        $this->scriptedBrowserRendererService = new ScriptedSessionBrowserRendererService();
    }

    protected function readPageTextSetting(string $settingName, mixed $default): mixed
    {
        return $this->pageTextSettings[$settingName] ?? $default;
    }

    protected function getGuardedHttpFetchService(): GuardedHttpFetchService
    {
        return $this->scriptedGuardedHttpFetchService;
    }

    protected function getBrowserHtmlMarkdownService(): BrowserHtmlMarkdownService
    {
        return new BrowserHtmlMarkdownService();
    }

    protected function getBrowserRendererService(): BrowserRendererService
    {
        return $this->scriptedBrowserRendererService;
    }
}
