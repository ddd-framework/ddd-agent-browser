<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\Browser\Services\Agents\BrowserAgentSessionsService;
use DDD\Domain\Browser\Services\BrowserOriginRulesService;
use DDD\Domain\Browser\Services\BrowserRendererService;
use DDD\Domain\Browser\Services\BrowserSessionsService;

/** The agent sessions service wired to fakes */
class WiredBrowserAgentSessionsService extends BrowserAgentSessionsService
{
    public function __construct(
        public ScriptedBrowserRendererService $scriptedBrowserRendererService = new ScriptedBrowserRendererService(),
        public InMemoryBrowserSessionsService $inMemoryBrowserSessionsService = new InMemoryBrowserSessionsService(),
        public RecordingOwnerConsentsService $recordingOwnerConsentsService = new RecordingOwnerConsentsService()
    ) {
    }

    protected function getBrowserRendererService(): BrowserRendererService
    {
        return $this->scriptedBrowserRendererService;
    }

    protected function getBrowserSessionsService(): BrowserSessionsService
    {
        return $this->inMemoryBrowserSessionsService;
    }

    protected function getBrowserOriginRulesService(): BrowserOriginRulesService
    {
        return new BrowserOriginRulesService();
    }

    protected function getOwnerConsentsService(): OwnerConsentsService
    {
        return $this->recordingOwnerConsentsService;
    }
}
