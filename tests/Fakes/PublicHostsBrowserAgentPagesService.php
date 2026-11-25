<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Services\Browser\Agents\BrowserAgentPagesService;

/** The page tools on the real renderer service, every http(s) host treated as public (no DNS in the suite) */
class PublicHostsBrowserAgentPagesService extends BrowserAgentPagesService
{
    public function findScreenshotRefusalReasonOfHost(string $url): ?string
    {
        return str_starts_with($url, 'https://') ? null : parent::findScreenshotRefusalReasonOfHost($url);
    }
}
