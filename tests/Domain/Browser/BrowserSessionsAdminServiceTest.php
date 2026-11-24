<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererHealth;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessions;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Tests\Browser\Fakes\WiredBrowserSessionsAdminService;
use PHPUnit\Framework\TestCase;

/** The admin side: an operator closes an open session, the renderer checks of the admin endpoint, the page size */
class BrowserSessionsAdminServiceTest extends TestCase
{
    public function testClosingAnOpenSessionClosesTheRendererContextAndLogsTheReason(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        $closedAnswer = new BrowserSessionAnswer();
        $closedAnswer->httpStatusCode = 200;
        $closedAnswer->reason = BrowserRendererContract::REASON_CLOSED;
        $wiredService->scriptedRendererService->scriptedAnswers = [$closedAnswer];
        $browserSession = $this->openSession('key-1');

        $closedSession = $wiredService->closeSession($browserSession, '  stuck on a login wall ');

        self::assertSame(BrowserSession::STATUS_CLOSED, $closedSession->status);
        self::assertNotNull($closedSession->closedAt);
        self::assertCount(1, $wiredService->scriptedRendererService->receivedRequests);
        self::assertSame(BrowserRendererContract::SESSION_ACTION_CLOSE, $wiredService->scriptedRendererService->receivedRequests[0]->action);
        self::assertSame('key-1', $wiredService->scriptedRendererService->receivedRequests[0]->sessionKey);
        self::assertSame(['CLOSED admin closed by an operator: stuck on a login wall; the renderer closed the context'], $wiredService->recordingSessionsService->recordedEvents);
    }

    public function testASessionWithoutARendererKeyIsClosedWithoutACall(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        $closedSession = $wiredService->closeSession($this->openSession(null), null);
        self::assertSame(BrowserSession::STATUS_CLOSED, $closedSession->status);
        self::assertSame([], $wiredService->scriptedRendererService->receivedRequests);
        self::assertSame(['CLOSED admin closed by an operator; the renderer held no context'], $wiredService->recordingSessionsService->recordedEvents);
    }

    public function testAnUnreachableRendererStillEndsTheRowAndSaysSo(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        $faultAnswer = new BrowserSessionAnswer();
        $faultAnswer->error = 'connection refused';
        $wiredService->scriptedRendererService->scriptedAnswers = [$faultAnswer];
        $closedSession = $wiredService->closeSession($this->openSession('key-2'), null);
        self::assertSame(BrowserSession::STATUS_CLOSED, $closedSession->status);
        self::assertStringContainsString('could not be reached (connection refused)', $wiredService->recordingSessionsService->recordedEvents[0]);
    }

    public function testASessionThatIsNoLongerOpenIsRefused(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        $browserSession = $this->openSession('key-3');
        $browserSession->status = BrowserSession::STATUS_GONE;
        $this->expectException(ConflictException::class);
        $wiredService->closeSession($browserSession, null);
    }

    public function testOneDeploymentForBothIsCheckedOnceAndMustServeSessions(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        $wiredService->scriptedRendererService->baseUrl = 'https://renderer.example.test';
        $wiredService->scriptedRendererService->healthByBaseUrl['https://renderer.example.test'] = $this->health('ok', BrowserRendererContract::CONTRACT_VERSION, BrowserRendererContract::ROLE_STATELESS);

        $deploymentChecks = $wiredService->checkRendererDeployments();

        self::assertSame(['https://renderer.example.test'], $wiredService->scriptedRendererService->probedBaseUrls);
        self::assertCount(1, $deploymentChecks->getElements());
        $deploymentCheck = $deploymentChecks->first();
        self::assertSame('stateless+sessions', $deploymentCheck->deployment);
        self::assertStringContainsString('answers no session routes', implode(' ', $deploymentCheck->problems));
        self::assertFalse($deploymentChecks->areAllHealthy());
    }

    public function testTwoFittingDeploymentsAreHealthy(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        $wiredService->scriptedRendererService->baseUrl = 'https://stateless.example.test';
        $wiredService->scriptedRendererService->sessionBaseUrl = 'https://sessions.example.test';
        $wiredService->scriptedRendererService->healthByBaseUrl = [
            'https://stateless.example.test' => $this->health('ok', BrowserRendererContract::CONTRACT_VERSION, BrowserRendererContract::ROLE_STATELESS),
            'https://sessions.example.test' => $this->health('ok', BrowserRendererContract::CONTRACT_VERSION, BrowserRendererContract::ROLE_SESSION),
        ];
        $deploymentChecks = $wiredService->checkRendererDeployments();
        self::assertCount(2, $deploymentChecks->getElements());
        self::assertTrue($deploymentChecks->areAllHealthy());
    }

    public function testNoConfiguredRendererIsNotHealthy(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        self::assertFalse($wiredService->checkRendererDeployments()->areAllHealthy());
    }

    public function testAPageLongerThanTheLimitHasMoreAndLosesTheExtraSession(): void
    {
        $wiredService = new WiredBrowserSessionsAdminService();
        $browserSessions = new BrowserSessions();
        foreach ([3, 2, 1] as $browserSessionId) {
            $browserSession = $this->openSession(null);
            $browserSession->id = $browserSessionId;
            $browserSessions->add($browserSession);
        }
        self::assertTrue($wiredService->hasMoreThan($browserSessions, 2));
        self::assertCount(2, $browserSessions->getElements());
        self::assertFalse($wiredService->hasMoreThan($browserSessions, 2));
        self::assertSame(50, $wiredService->normalizeSessionsLimit(0));
        self::assertSame(200, $wiredService->normalizeSessionsLimit(5000));
    }

    protected function openSession(?string $sessionKey): BrowserSession
    {
        $browserSession = new BrowserSession();
        $browserSession->id = 7;
        $browserSession->conversationId = 11;
        $browserSession->status = BrowserSession::STATUS_OPEN;
        $browserSession->sessionKey = $sessionKey;
        $browserSession->currentUrl = 'https://www.example.com/';
        return $browserSession;
    }

    protected function health(string $status, string $contractVersion, string $role): BrowserRendererHealth
    {
        $browserRendererHealth = new BrowserRendererHealth();
        $browserRendererHealth->status = $status;
        $browserRendererHealth->contractVersion = $contractVersion;
        $browserRendererHealth->role = $role;
        $browserRendererHealth->httpStatusCode = 200;
        return $browserRendererHealth;
    }
}
