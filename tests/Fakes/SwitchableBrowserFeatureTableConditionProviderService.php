<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Services\Browser\Installation\BrowserFeatureTableConditionProviderService;

/** The session table conditions with the sessions switch set by the test */
class SwitchableBrowserFeatureTableConditionProviderService extends BrowserFeatureTableConditionProviderService
{
    public bool $sessionsEnabled = false;

    protected function areSessionsEnabled(): bool
    {
        return $this->sessionsEnabled;
    }
}
