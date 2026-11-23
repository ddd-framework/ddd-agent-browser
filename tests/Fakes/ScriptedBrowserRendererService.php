<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;
use DDD\Domain\Browser\Services\BrowserRendererService;

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
