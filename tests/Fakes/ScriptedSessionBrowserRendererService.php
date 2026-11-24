<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

/** A scripted renderer with a sessions endpoint (null = none configured) */
class ScriptedSessionBrowserRendererService extends ScriptedBrowserRendererService
{
    public ?string $sessionBaseUrl = 'https://renderer.example.test';

    public function getSessionBaseUrl(): ?string
    {
        return $this->sessionBaseUrl;
    }
}
