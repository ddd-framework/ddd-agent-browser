<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererDeploymentCheck;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererDeploymentChecks;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererHealth;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionRequest;
use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\AI\Repo\Argus\Browser\Renderer\ArgusBrowserRendererHealth;
use DDD\Domain\AI\Repo\Argus\Browser\Sessions\ArgusBrowserSessionAnswer;
use DDD\Infrastructure\Http\PublicUrlGuard;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * The browser renderer as seen from PHP: where its deployments are (`AI.Browser.renderer`), the caller token, the calls of
 * the session routes and the health probe. A navigation target is checked here first (a private or unresolvable host
 * is never sent); the renderer checks every request and redirect hop again.
 */
class BrowserRendererService extends Service
{
    /** @var int Seconds PHP waits for one session action by default: above the renderer's deadlines (navigate 45 s, analyse 60 s) */
    public const int DEFAULT_SESSION_ACTION_TIMEOUT_SECONDS = 65;

    /** @var int Seconds PHP waits for one page screenshot by default */
    public const int DEFAULT_SCREENSHOT_TIMEOUT_SECONDS = 40;

    /**
     * The absolute base URL of the stateless deployment; null when none is configured.
     * @return string|null
     */
    public function getBaseUrl(): ?string
    {
        return $this->readConfiguredUrl('endpointEnv', 'endpoint');
    }

    /**
     * The absolute base URL of the sessions deployment (`sessionEndpoint`, else the stateless one); null when none.
     * @return string|null
     */
    public function getSessionBaseUrl(): ?string
    {
        return $this->readConfiguredUrl('sessionEndpointEnv', 'sessionEndpoint') ?? $this->getBaseUrl();
    }

    public function isSessionEndpointConfigured(): bool
    {
        return $this->getSessionBaseUrl() !== null;
    }

    /**
     * The caller token (it names the caller class on the renderer); null when none is configured.
     * @return string|null
     */
    public function getCallerToken(): ?string
    {
        $tokenEnv = Config::get('AI.Browser.renderer.tokenEnv');
        $token = is_string($tokenEnv) && $tokenEnv !== '' ? $this->readEnv($tokenEnv) : '';
        return $token !== '' ? $token : null;
    }

    /**
     * Runs one session route. A navigation (or a tab opened with a URL) whose host is not public is answered as
     * `BLOCKED` without a call.
     * @param BrowserSessionRequest $browserSessionRequest
     * @return BrowserSessionAnswer
     */
    public function runSessionAction(BrowserSessionRequest $browserSessionRequest): BrowserSessionAnswer
    {
        $sessionBaseUrl = $this->getSessionBaseUrl();
        if ($sessionBaseUrl === null) {
            $browserSessionAnswer = new BrowserSessionAnswer();
            $browserSessionAnswer->action = $browserSessionRequest->action;
            $browserSessionAnswer->error = 'no browser renderer is configured (AI.Browser.renderer)';
            return $browserSessionAnswer;
        }
        if ($browserSessionRequest->url !== null && !$this->isPublicHttpUrl($browserSessionRequest->url)) {
            $browserSessionAnswer = new BrowserSessionAnswer();
            $browserSessionAnswer->action = $browserSessionRequest->action;
            $browserSessionAnswer->httpStatusCode = 200;
            $browserSessionAnswer->sessionKey = $browserSessionRequest->sessionKey;
            $browserSessionAnswer->reason = BrowserRendererContract::REASON_BLOCKED;
            return $browserSessionAnswer;
        }
        $argusBrowserSessionAnswer = $this->createSessionAnswerCall();
        $argusBrowserSessionAnswer->call(
            $browserSessionRequest,
            $sessionBaseUrl,
            $this->getCallerToken(),
            max(5, (int)(Config::get('AI.Browser.renderer.sessionActionTimeoutSeconds') ?? static::DEFAULT_SESSION_ACTION_TIMEOUT_SECONDS))
        );
        return $argusBrowserSessionAnswer;
    }

    /**
     * Probes a deployment's `/healthz`.
     * @param string $baseUrl
     * @return BrowserRendererHealth
     */
    public function probeHealth(string $baseUrl): BrowserRendererHealth
    {
        $argusBrowserRendererHealth = $this->createHealthProbe();
        $argusBrowserRendererHealth->probe($baseUrl, max(2, (int)(Config::get('AI.Browser.renderer.healthTimeoutSeconds') ?? 10)));
        return $argusBrowserRendererHealth;
    }

    /**
     * Checks every configured deployment against this package: `/healthz` answers ready, the contract version fits,
     * and the deployment of the session routes serves sessions (role `session` or `all`). One deployment configured for
     * both is checked once, as `stateless+sessions`. Empty when no renderer is configured.
     * @return BrowserRendererDeploymentChecks
     */
    public function checkDeployments(): BrowserRendererDeploymentChecks
    {
        $browserRendererDeploymentChecks = new BrowserRendererDeploymentChecks();
        $baseUrl = $this->getBaseUrl();
        $sessionBaseUrl = $this->getSessionBaseUrl();
        if ($baseUrl !== null && $sessionBaseUrl === $baseUrl) {
            $browserRendererDeploymentCheck = $this->checkDeployment('stateless+sessions', $baseUrl, true);
            $browserRendererDeploymentChecks->add($browserRendererDeploymentCheck);
            return $browserRendererDeploymentChecks;
        }
        if ($baseUrl !== null) {
            $browserRendererDeploymentCheck = $this->checkDeployment('stateless', $baseUrl, false);
            $browserRendererDeploymentChecks->add($browserRendererDeploymentCheck);
        }
        if ($sessionBaseUrl !== null) {
            $browserRendererDeploymentCheck = $this->checkDeployment('sessions', $sessionBaseUrl, true);
            $browserRendererDeploymentChecks->add($browserRendererDeploymentCheck);
        }
        return $browserRendererDeploymentChecks;
    }

    /**
     * Probes one deployment and names what does not fit this package.
     * @param string $deploymentName
     * @param string $baseUrl
     * @param bool $mustServeSessions
     * @return BrowserRendererDeploymentCheck
     */
    public function checkDeployment(string $deploymentName, string $baseUrl, bool $mustServeSessions): BrowserRendererDeploymentCheck
    {
        $browserRendererHealth = $this->probeHealth($baseUrl);
        $problems = [];
        if ($browserRendererHealth->httpStatusCode === null) {
            $problems[] = 'no answer (' . ($browserRendererHealth->failureReason ?? 'unreachable') . ')';
        } elseif (!$browserRendererHealth->isReady()) {
            $problems[] = 'not ready (HTTP ' . $browserRendererHealth->httpStatusCode . ', status ' . ($browserRendererHealth->status ?? '?') . ')';
        }
        if ($browserRendererHealth->httpStatusCode !== null && !BrowserRendererContract::isCompatibleContractVersion($browserRendererHealth->contractVersion)) {
            $problems[] = 'contract ' . ($browserRendererHealth->contractVersion ?? 'unknown') . ' does not serve this package (needs ' . BrowserRendererContract::MINIMUM_CONTRACT_VERSION . ' or newer within the major)';
        }
        if ($mustServeSessions && $browserRendererHealth->httpStatusCode !== null && !$browserRendererHealth->servesSessions()) {
            $problems[] = 'role ' . ($browserRendererHealth->role ?? 'unknown') . ' answers no session routes (needs RENDER_ROLE=session or all)';
        }
        $browserRendererDeploymentCheck = new BrowserRendererDeploymentCheck();
        $browserRendererDeploymentCheck->deployment = $deploymentName;
        $browserRendererDeploymentCheck->baseUrl = $baseUrl;
        $browserRendererDeploymentCheck->health = $browserRendererHealth;
        $browserRendererDeploymentCheck->problems = $problems;
        return $browserRendererDeploymentCheck;
    }

    /**
     * An http(s) URL whose host resolves only to public addresses.
     * @param string $url
     * @return bool
     */
    public function isPublicHttpUrl(string $url): bool
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        return PublicUrlGuard::classifyHost($url) === PublicUrlGuard::HOST_PUBLIC;
    }

    /** Whether a stateless renderer deployment is configured (`AI.Browser.renderer.endpoint` / `endpointEnv`) */
    public function isStatelessEndpointConfigured(): bool
    {
        return $this->getBaseUrl() !== null;
    }

    /**
     * Whether the page tools can render: the page screenshot call says so
     * ({@see ArgusBrowserPageScreenshot::isRenderingAvailable()}; by default a stateless endpoint is configured, an
     * application's same-path subclass of the call decides by its own configuration).
     */
    public function isPageRenderingAvailable(): bool
    {
        return $this->createPageScreenshotLoader()->isRenderingAvailable();
    }

    /**
     * The page screenshot call, prepared with the configured stateless deployment, the caller token and the timeout
     * (`AI.Browser.renderer.screenshotTimeoutSeconds`); null when page rendering is not available.
     * @return ArgusBrowserPageScreenshot|null
     */
    public function createPageScreenshotCall(): ?ArgusBrowserPageScreenshot
    {
        $argusBrowserPageScreenshot = $this->createPageScreenshotLoader();
        if (!$argusBrowserPageScreenshot->isRenderingAvailable()) {
            return null;
        }
        $argusBrowserPageScreenshot->prepareCall(
            $this->getBaseUrl(),
            $this->getCallerToken(),
            max(5, (int)(Config::get('AI.Browser.renderer.screenshotTimeoutSeconds') ?? static::DEFAULT_SCREENSHOT_TIMEOUT_SECONDS))
        );
        return $argusBrowserPageScreenshot;
    }

    /**
     * A new page screenshot call: an application's same-path subclass of {@see ArgusBrowserPageScreenshot} when there
     * is one (`newInstance()`), the package's class otherwise.
     */
    protected function createPageScreenshotLoader(): ArgusBrowserPageScreenshot
    {
        return ArgusBrowserPageScreenshot::newInstance();
    }

    protected function createSessionAnswerCall(): ArgusBrowserSessionAnswer
    {
        return new ArgusBrowserSessionAnswer();
    }

    protected function createHealthProbe(): ArgusBrowserRendererHealth
    {
        return new ArgusBrowserRendererHealth();
    }

    protected function readConfiguredUrl(string $envKey, string $urlKey): ?string
    {
        $envName = Config::get('AI.Browser.renderer.' . $envKey);
        $url = is_string($envName) && $envName !== '' ? $this->readEnv($envName) : '';
        if ($url === '') {
            $configuredUrl = Config::get('AI.Browser.renderer.' . $urlKey);
            $url = is_string($configuredUrl) ? $configuredUrl : '';
        }
        $url = trim($url);
        return preg_match('~^https?://~i', $url) === 1 ? rtrim($url, '/') : null;
    }

    /**
     * An environment variable as text: the framework's env (Dotenv), else the process environment; '' when unset.
     * @param string $envName
     * @return string
     */
    protected function readEnv(string $envName): string
    {
        $value = Config::getEnv($envName);
        if ($value === null || $value === false || $value === '') {
            $value = getenv($envName);
        }
        return is_scalar($value) && $value !== false ? trim((string)$value) : '';
    }
}
