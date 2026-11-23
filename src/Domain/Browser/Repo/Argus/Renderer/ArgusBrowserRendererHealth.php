<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Repo\Argus\Renderer;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererHealth;

/**
 * Probes a renderer deployment's `GET /healthz` (no auth). 200 `{status: ok, contractVersion, chromeVersion, role}`, or
 * 503 `{status: starting, contractVersion, role}` while Chrome starts. Never throws.
 */
#[ArgusLoad(loadEndpoint: 'GET:/healthz', cacheLevel: ArgusCache::CACHELEVEL_NONE, timeout: 10)]
class ArgusBrowserRendererHealth extends BrowserRendererHealth
{
    use ArgusTrait;

    /**
     * @param string $baseUrl absolute base URL of the deployment
     * @param int $timeoutSeconds
     * @return void
     */
    public function probe(string $baseUrl, int $timeoutSeconds): void
    {
        $argusSettings = $this->getArgusSettings();
        $argusSettings->argusLoad->loadEndpoint = 'GET:' . rtrim($baseUrl, '/') . BrowserRendererContract::ROUTE_HEALTH;
        $argusSettings->argusLoad->timeout = (float)$timeoutSeconds;
        $argusSettings->keepFulfilledCallsWhenOneIsRejected = true;
        $this->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        if ($this->httpStatusCode === null && $this->failureReason === null) {
            $this->failureReason = 'the renderer did not answer';
        }
    }

    protected function getLoadPayload(): ?array
    {
        return [ArgusApiOperation::EXTERNAL_CALL => true];
    }

    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $this->httpStatusCode = $apiOperation?->responseStatusCode;
        if (!is_object($callResponseData)) {
            $this->failureReason = $apiOperation?->rejectionReason ?? 'no readable answer';
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        $this->httpStatusCode ??= is_numeric($callResponseData->status ?? null) ? (int)$callResponseData->status : 200;
        $this->status = is_scalar($callResponseData->status ?? null) ? (string)$callResponseData->status : null;
        $this->contractVersion = is_scalar($callResponseData->contractVersion ?? null) ? (string)$callResponseData->contractVersion : null;
        $this->chromeVersion = is_scalar($callResponseData->chromeVersion ?? null) ? (string)$callResponseData->chromeVersion : null;
        $this->role = is_scalar($callResponseData->role ?? null) ? (string)$callResponseData->role : null;
        $this->postProcessLoadResponse($callResponseData, true);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
