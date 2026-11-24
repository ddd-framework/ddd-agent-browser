<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Services\Browser\BrowserSessionsService;

/** Sessions without a database: saves are counted, log entries kept as `TYPE tool detail` */
class RecordingBrowserSessionsService extends BrowserSessionsService
{
    /** @var string[] The log entries written */
    public array $recordedEvents = [];

    public int $savedSessionCount = 0;

    public function saveSession(BrowserSession $browserSession): BrowserSession
    {
        $this->savedSessionCount++;
        return $browserSession;
    }

    public function recordEvent(BrowserSession $browserSession, string $eventType, ?string $toolName, ?string $url, ?string $detail): void
    {
        $this->recordedEvents[] = trim($eventType . ' ' . $toolName . ' ' . $detail);
    }
}
