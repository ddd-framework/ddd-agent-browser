<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services;

use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererHealth;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;
use DDD\Domain\Browser\Repo\Argus\Renderer\ArgusBrowserRendererHealth;
use DDD\Domain\Browser\Repo\Argus\Sessions\ArgusBrowserSessionAnswer;
use DDD\Infrastructure\Http\PublicUrlGuard;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * The browser renderer as seen from PHP: where its deployments are (`Browser.renderer`), the caller token, the calls of
 * the session routes and the health probe. A navigation target is checked here first (a private or unresolvable host
 * is never sent); the renderer checks every request and redirect hop again.
 */
class BrowserRendererService extends Service
{
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
        $tokenEnv = Config::get('Browser.renderer.tokenEnv');
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
            $browserSessionAnswer->error = 'no browser renderer is configured (Browser.renderer)';
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
            max(5, (int)(Config::get('Browser.renderer.sessionActionTimeoutSeconds') ?? 40))
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
        $argusBrowserRendererHealth->probe($baseUrl, max(2, (int)(Config::get('Browser.renderer.healthTimeoutSeconds') ?? 10)));
        return $argusBrowserRendererHealth;
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
        $envName = Config::get('Browser.renderer.' . $envKey);
        $url = is_string($envName) && $envName !== '' ? $this->readEnv($envName) : '';
        if ($url === '') {
            $configuredUrl = Config::get('Browser.renderer.' . $urlKey);
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
