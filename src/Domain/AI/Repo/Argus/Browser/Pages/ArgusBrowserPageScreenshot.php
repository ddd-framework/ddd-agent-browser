<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Browser\Pages;

use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use Throwable;

/**
 * A URL rendered to a base64 PNG through the browser renderer's stateless route
 * (`POST <endpoint>/webPageRenderer/screenshot`, {@see BrowserRendererContract::ROUTE_SCREENSHOT}): the real page,
 * opaque, JavaScript on, full page up to the renderer's height cap, waiting for network idle. Answer
 * `{"status": 200, "data": {"reason": "OK", "content": "<base64>", …}}`; the bytes land on
 * {@see BrowserPageScreenshot::$content}. Any other `data.reason` (BLOCKED, TIMEOUT, BOT_CHALLENGE, …), a renderer
 * fault (HTTP status ≠ 200; 429 = {@see BrowserRendererContract::REASON_BUSY}) or a transport failure lands on
 * {@see BrowserPageScreenshot::$failureReason}; a failure is never cached.
 *
 * The endpoint and the caller token are set by {@see prepareCall()}; as an external call it carries none of the
 * application's own headers or tokens. Cached in memory for ten minutes per URL and viewport: a capture is megabytes of
 * base64, every part of one page reuses it.
 *
 * @method BrowserPageScreenshot|null toEntity()
 */
#[ArgusLoad(
    loadEndpoint: 'POST:' . BrowserRendererContract::ROUTE_SCREENSHOT,
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY,
    cacheTtl: ArgusCache::CACHE_TTL_TEN_MINUTES, // a live page changes; a short lifetime keeps repeat views cheap
    timeout: 40
)]
class ArgusBrowserPageScreenshot extends BrowserPageScreenshot
{
    use ArgusTrait;

    /** @var string|null The URL to render and capture */
    public ?string $url = null;

    /** @var int Browser viewport width in px (native capture width) */
    public int $viewportWidth = 1024;

    /** @var int Browser viewport height in px (the window; the full-page capture extends below it) */
    public int $viewportHeight = 1400;

    /** @var bool Capture the whole scrollable page (true) or the window only (false) */
    public bool $fullPage = true;

    /** @var bool Emulate a phone (the renderer's `mobile` device) */
    public bool $isMobile = false;

    /** @var int Page-load timeout in seconds (the renderer accepts 5–45) */
    public int $domTimeoutSeconds = 30;

    /** @var int Extra seconds to wait after load before capturing (lets late JavaScript settle) */
    public int $minWaitSeconds = 2;

    protected ?string $callerToken = null;

    /**
     * Points this loader at a renderer deployment.
     * @param string $baseUrl absolute base URL of the stateless deployment
     * @param string|null $callerToken the caller class's token
     * @param int $timeoutSeconds how long PHP waits for the answer
     * @return void
     */
    public function prepareCall(string $baseUrl, ?string $callerToken, int $timeoutSeconds): void
    {
        $this->callerToken = $callerToken;
        $argusSettings = $this->getArgusSettings();
        $argusSettings->argusLoad->loadEndpoint = 'POST:' . rtrim($baseUrl, '/') . BrowserRendererContract::ROUTE_SCREENSHOT;
        $argusSettings->argusLoad->timeout = (float)$timeoutSeconds;
        // a refused / timed-out call answers null instead of throwing out of the load
        $argusSettings->keepFulfilledCallsWhenOneIsRejected = true;
    }

    /**
     * Stores the base64 image on success. Otherwise the reason the answer or the rejected call names lands on
     * {@see BrowserPageScreenshot::$failureReason} and nothing is cached.
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null,
    ): void {
        $data = is_object($callResponseData) && is_object($callResponseData->data ?? null) ? $callResponseData->data : null;
        $httpStatusCode = $apiOperation?->responseStatusCode
            ?? (is_object($callResponseData) && is_numeric($callResponseData->status ?? null) ? (int)$callResponseData->status : null);
        if ($data !== null && ($httpStatusCode === null || $httpStatusCode === 200) && ($data->reason ?? null) === BrowserRendererContract::REASON_OK
            && is_string($data->content ?? null) && $data->content !== ''
        ) {
            $this->content = $data->content;
            $this->failureReason = null;
            $this->postProcessLoadResponse($callResponseData);
            return;
        }
        $this->content = null;
        $this->failureReason = static::findFailureReasonOfAnswer($callResponseData, $apiOperation);
        $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
    }

    /**
     * The failure code of a renderer answer: `data.reason` (or `reason`) when it is a code, upper-cased; HTTP 429 →
     * BUSY; for a call without an answer TIMEOUT when its reason says so, else FETCH_FAILED.
     */
    public static function findFailureReasonOfAnswer(mixed $callResponseData, ?ArgusApiOperation $apiOperation = null): string
    {
        $reasonCandidates = [];
        if (is_object($callResponseData)) {
            $reasonCandidates[] = is_object($callResponseData->data ?? null) ? ($callResponseData->data->reason ?? null) : null;
            $reasonCandidates[] = $callResponseData->reason ?? null;
        }
        foreach ($reasonCandidates as $reasonCandidate) {
            if (is_string($reasonCandidate) && $reasonCandidate !== BrowserRendererContract::REASON_OK
                && preg_match('/^[A-Za-z][A-Za-z0-9_]{1,40}$/', trim($reasonCandidate)) === 1
            ) {
                return strtoupper(trim($reasonCandidate));
            }
        }
        $httpStatusCode = $apiOperation?->responseStatusCode
            ?? (is_object($callResponseData) && is_numeric($callResponseData->status ?? null) ? (int)$callResponseData->status : null);
        if ($httpStatusCode === 429) {
            return BrowserRendererContract::REASON_BUSY;
        }
        $rejectionReason = $apiOperation !== null ? trim((string)($apiOperation->rejectionReason ?? '')) : '';
        return stripos($rejectionReason, 'timed out') !== false || stripos($rejectionReason, 'timeout') !== false
            ? BrowserRendererContract::REASON_TIMEOUT
            : BrowserRendererContract::REASON_FETCH_FAILED;
    }

    /**
     * The failure code of a call that threw before any answer (a connection error, the client's timeout): TIMEOUT when
     * the message says so, else FETCH_FAILED.
     */
    public static function findFailureReasonOfTransportError(Throwable $throwable): string
    {
        $message = $throwable->getMessage();
        return stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false || stripos($message, 'cURL error 28') !== false
            ? BrowserRendererContract::REASON_TIMEOUT
            : BrowserRendererContract::REASON_FETCH_FAILED;
    }

    /**
     * The capture is identified by WHAT it renders (url, viewport width and height, device, full page): the cache is
     * per page and viewport. The height belongs to it: the tall-window retry must not be answered by the short capture.
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(md5(($this->url ?? '') . '|' . $this->viewportWidth . 'x' . $this->viewportHeight . '|' . ($this->isMobile ? 'm' : 'd') . '|' . ($this->fullPage ? 'full' : 'fold')));
    }

    /**
     * The renderer's request body. Null when no url is set, so the load stops instead of calling with an empty target.
     */
    protected function getLoadPayload(): ?array
    {
        if ($this->url === null || $this->url === '') {
            return null;
        }
        $headers = ['Content-Type' => 'application/json'];
        if ($this->callerToken !== null && $this->callerToken !== '') {
            $headers[BrowserRendererContract::TOKEN_HEADER] = $this->callerToken;
        }
        return [
            'body' => $this->buildRendererBody(),
            'headers' => $headers,
            // a call outside the application's services: no bearer token, no tracking or debug headers
            ArgusApiOperation::EXTERNAL_CALL => true,
        ];
    }

    /**
     * The camelCase body of the stateless screenshot route.
     */
    public function buildRendererBody(): array
    {
        return [
            'url' => (string)$this->url,
            'device' => $this->isMobile ? 'mobile' : 'desktop',
            'viewportWidth' => $this->viewportWidth,
            'viewportHeight' => $this->viewportHeight,
            'fullPage' => $this->fullPage,
            'format' => 'png',
            'density' => 1,
            'waitUntil' => 'networkidle0',
            'minWaitSeconds' => $this->minWaitSeconds,
            'domTimeoutSeconds' => $this->domTimeoutSeconds,
            'javascriptEnabled' => true,
            'redirectEnabled' => true,
            // opaque: a description model needs the REAL rendered background
            'omitBackground' => false,
        ];
    }
}
