<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser\Installation;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionEvent;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Services\Browser\BrowserAccessService;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The tables of the browser sessions for `app:agent:check-installation` of ddd-agents (>= 0.5.0): `BrowserSessions` and
 * `BrowserSessionEvents` are read only while interactive sessions are switched on (`AI.Browser.sessions.enabled`), so
 * a host with sessions off may leave them out. Registered only beside ddd-agents 0.5.0 or later
 * (`AgentBrowserModule::getNamespacesRequiringClasses()`).
 */
class BrowserFeatureTableConditionProviderService extends Service implements AgentFeatureTableConditionProvider
{
    /** @var string What reads the tables, for the reason of the check */
    public const string SESSIONS_FEATURE_DESCRIPTION = 'the interactive browser sessions (AI.Browser.sessions.enabled)';

    public function getFeatureTableConditions(): AgentFeatureTableConditions
    {
        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        $areSessionsEnabled = $this->areSessionsEnabled();
        foreach ([BrowserSession::class, BrowserSessionEvent::class] as $entityClassName) {
            $sessionTableCondition = AgentFeatureTableCondition::create($entityClassName, static::SESSIONS_FEATURE_DESCRIPTION, $areSessionsEnabled);
            $agentFeatureTableConditions->add($sessionTableCondition);
        }
        return $agentFeatureTableConditions;
    }

    /** Whether interactive sessions are switched on */
    protected function areSessionsEnabled(): bool
    {
        /** @var BrowserAccessService $browserAccessService */
        $browserAccessService = DDDService::instance()->getService(BrowserAccessService::class);
        return $browserAccessService->areSessionsEnabled();
    }
}
