<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\Browser\Repo\Argus\Sessions\ArgusBrowserSessionAnswer;
use DDD\Domain\Browser\Services\BrowserRendererService;
use LogicException;

/** A renderer service with a fixed sessions endpoint that must never reach the wire */
class FixedEndpointBrowserRendererService extends BrowserRendererService
{
    public function getSessionBaseUrl(): ?string
    {
        return 'https://renderer.example.test';
    }

    protected function createSessionAnswerCall(): ArgusBrowserSessionAnswer
    {
        throw new LogicException('the renderer was called');
    }
}
