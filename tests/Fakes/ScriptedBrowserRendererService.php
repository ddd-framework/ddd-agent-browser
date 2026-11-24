<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionRequest;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;

/** A renderer that answers from a script and records every request */
class ScriptedBrowserRendererService extends BrowserRendererService
{
    /** @var BrowserSessionAnswer[] Answers in call order */
    public array $scriptedAnswers = [];

    /** @var BrowserSessionRequest[] The requests received */
    public array $receivedRequests = [];

    public function runSessionAction(BrowserSessionRequest $browserSessionRequest): BrowserSessionAnswer
    {
        $this->receivedRequests[] = clone $browserSessionRequest;
        $browserSessionAnswer = array_shift($this->scriptedAnswers) ?? new BrowserSessionAnswer();
        $browserSessionAnswer->action = $browserSessionRequest->action;
        return $browserSessionAnswer;
    }
}
