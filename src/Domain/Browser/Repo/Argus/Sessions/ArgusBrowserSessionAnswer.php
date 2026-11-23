<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Repo\Argus\Sessions;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;

/**
 * Calls one session route of the renderer's sessions deployment. Never throws for an answer: a page outcome fills the
 * answer, a renderer fault sets `httpStatusCode`, `error` and (429) `retryAfterSeconds`, a transport failure leaves
 * `httpStatusCode` null with `error` set. The endpoint and the caller token come from {@see call()}; as an
 * external call it carries none of the application's own headers or tokens.
 */
#[ArgusLoad(loadEndpoint: 'POST:/browser/session/navigate', cacheLevel: ArgusCache::CACHELEVEL_NONE, timeout: 65)]
class ArgusBrowserSessionAnswer extends BrowserSessionAnswer
{
    use ArgusTrait;

    protected ?BrowserSessionRequest $browserSessionRequest = null;

    protected ?string $callerToken = null;

    /**
     * Sends the request and waits for its answer; the outcome is on this instance afterwards.
     * @param BrowserSessionRequest $browserSessionRequest
     * @param string $sessionBaseUrl absolute base URL of the sessions deployment
     * @param string|null $callerToken the caller class's token
     * @param int $timeoutSeconds
     * @return void
     */
    public function call(BrowserSessionRequest $browserSessionRequest, string $sessionBaseUrl, ?string $callerToken, int $timeoutSeconds): void
    {
        $this->browserSessionRequest = $browserSessionRequest;
        $this->callerToken = $callerToken;
        $this->action = $browserSessionRequest->action;
        $this->sessionKey = $browserSessionRequest->sessionKey;
        $argusSettings = $this->getArgusSettings();
        $argusSettings->argusLoad->loadEndpoint = 'POST:' . rtrim($sessionBaseUrl, '/') . $browserSessionRequest->buildRoute();
        $argusSettings->argusLoad->timeout = (float)$timeoutSeconds;
        // a refused / timed-out call answers null instead of throwing out of the load
        $argusSettings->keepFulfilledCallsWhenOneIsRejected = true;
        $this->argusLoad(useArgusEntityCache: false, useApiACallCache: false);
        if ($this->httpStatusCode === null && $this->error === null) {
            $this->error = 'the renderer did not answer';
        }
    }

    protected function getLoadPayload(): ?array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->callerToken !== null && $this->callerToken !== '') {
            $headers[BrowserRendererContract::TOKEN_HEADER] = $this->callerToken;
        }
        return [
            'body' => $this->browserSessionRequest?->toRendererBody() ?? [],
            'headers' => $headers,
            // a call outside the application's services: no bearer token, no tracking or debug headers
            ArgusApiOperation::EXTERNAL_CALL => true,
        ];
    }

    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $this->httpStatusCode = $apiOperation?->responseStatusCode
            ?? (is_object($callResponseData) && isset($callResponseData->status) && is_numeric($callResponseData->status) ? (int)$callResponseData->status : null);
        if ($callResponseData === null) {
            $this->error = $apiOperation?->rejectionReason ?? 'transport failure';
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        if ($this->httpStatusCode !== 200) {
            $this->error = is_object($callResponseData) && is_scalar($callResponseData->error ?? null)
                ? (string)$callResponseData->error
                : (static::rejectionReasonForNon2xxAnswer($callResponseData, $apiOperation) ?? 'renderer fault');
            $retryAfterValues = $apiOperation?->responseHeaders['Retry-After'] ?? $apiOperation?->responseHeaders['retry-after'] ?? null;
            $retryAfter = is_array($retryAfterValues) ? ($retryAfterValues[0] ?? null) : $retryAfterValues;
            $this->retryAfterSeconds = is_numeric($retryAfter) ? (int)$retryAfter : null;
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        $data = $this->getResponseDataFromArgusResponse($callResponseData);
        if (!is_object($data)) {
            $this->error = 'the renderer answered without data';
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        $this->fillFromRendererData($data);
        $this->postProcessLoadResponse($callResponseData, true);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
