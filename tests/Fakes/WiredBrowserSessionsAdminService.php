<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Domain\AI\Services\Browser\BrowserSessionsAdminService;
use DDD\Domain\AI\Services\Browser\BrowserSessionsService;

/** The admin service on a scripted renderer and sessions without a database */
class WiredBrowserSessionsAdminService extends BrowserSessionsAdminService
{
    public ScriptedHealthBrowserRendererService $scriptedRendererService;

    public RecordingBrowserSessionsService $recordingSessionsService;

    public function __construct()
    {
        $this->scriptedRendererService = new ScriptedHealthBrowserRendererService();
        $this->recordingSessionsService = new RecordingBrowserSessionsService();
    }

    protected function getBrowserRendererService(): BrowserRendererService
    {
        return $this->scriptedRendererService;
    }

    protected function getBrowserSessionsService(): BrowserSessionsService
    {
        return $this->recordingSessionsService;
    }
}
