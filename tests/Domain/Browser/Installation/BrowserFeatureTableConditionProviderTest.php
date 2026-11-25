<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser\Installation;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionEvent;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Modules\AgentBrowser\AgentBrowserModule;
use DDD\Tests\Browser\Fakes\SwitchableBrowserFeatureTableConditionProviderService;
use PHPUnit\Framework\TestCase;

/** The session tables are required by app:agent:check-installation of ddd-agents only while sessions are switched on */
class BrowserFeatureTableConditionProviderTest extends TestCase
{
    public function testTheSessionTablesAreNotRequiredWhileSessionsAreOff(): void
    {
        $providerService = new SwitchableBrowserFeatureTableConditionProviderService();
        $agentFeatureTableConditions = $providerService->getFeatureTableConditions();

        self::assertStringContainsString('switched off', (string)$agentFeatureTableConditions->findReasonTableIsNotRequired(BrowserSession::class, []));
        self::assertStringContainsString('switched off', (string)$agentFeatureTableConditions->findReasonTableIsNotRequired(BrowserSessionEvent::class, []));
    }

    public function testTheSessionTablesAreRequiredWhileSessionsAreOn(): void
    {
        $providerService = new SwitchableBrowserFeatureTableConditionProviderService();
        $providerService->sessionsEnabled = true;
        $agentFeatureTableConditions = $providerService->getFeatureTableConditions();

        self::assertNull($agentFeatureTableConditions->findReasonTableIsNotRequired(BrowserSession::class, []));
        self::assertNull($agentFeatureTableConditions->findReasonTableIsNotRequired(BrowserSessionEvent::class, []));
    }

    public function testTheProviderRegistersOnlyBesideTheTableConditionPort(): void
    {
        self::assertInstanceOf(AgentFeatureTableConditionProvider::class, new SwitchableBrowserFeatureTableConditionProviderService());
        self::assertSame(
            AgentFeatureTableConditionProvider::class,
            AgentBrowserModule::getNamespacesRequiringClasses()['DDD\\Domain\\AI\\Services\\Browser\\Installation\\']
        );
    }
}
