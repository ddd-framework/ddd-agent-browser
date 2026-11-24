<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Services\Browser\BrowserSessionsService;

/** Sessions without a database: the answer's key is taken over, log entries are kept in memory */
class InMemoryBrowserSessionsService extends BrowserSessionsService
{
    /** @var string[] Recorded log entries as `TYPE url` */
    public array $recordedEvents = [];

    public function applyAnswerToSession(BrowserSession $browserSession, BrowserSessionAnswer $browserSessionAnswer): BrowserSession
    {
        if ($browserSessionAnswer->sessionKey !== null) {
            $browserSession->sessionKey = $browserSessionAnswer->sessionKey;
        }
        return $browserSession;
    }

    public function recordEvent(BrowserSession $browserSession, string $eventType, ?string $toolName, ?string $url, ?string $detail): void
    {
        $this->recordedEvents[] = trim($eventType . ' ' . $url);
    }
}
