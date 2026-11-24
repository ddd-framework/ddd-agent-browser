<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererHealth;

/** A renderer with configured base URLs whose health answers come from a script by base URL */
class ScriptedHealthBrowserRendererService extends ScriptedBrowserRendererService
{
    public ?string $baseUrl = null;

    public ?string $sessionBaseUrl = null;

    /** @var BrowserRendererHealth[] Health answers by base URL */
    public array $healthByBaseUrl = [];

    /** @var string[] The base URLs probed */
    public array $probedBaseUrls = [];

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function getSessionBaseUrl(): ?string
    {
        return $this->sessionBaseUrl ?? $this->baseUrl;
    }

    public function probeHealth(string $baseUrl): BrowserRendererHealth
    {
        $this->probedBaseUrls[] = $baseUrl;
        return $this->healthByBaseUrl[$baseUrl] ?? new BrowserRendererHealth();
    }
}
