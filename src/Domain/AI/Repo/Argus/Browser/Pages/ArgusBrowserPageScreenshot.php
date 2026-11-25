<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Browser\Pages;

use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The page screenshot call of the page tools: a URL rendered to a base64 PNG, full page, opaque, JavaScript on,
 * waiting for network idle. By default it is the browser renderer's stateless route
 * (`POST <AI.Browser.renderer.endpoint>/webPageRenderer/screenshot`, {@see BrowserRendererContract::ROUTE_SCREENSHOT})
 * as an external call with the caller token; answer `{"status": 200, "data": {"reason": "OK", "content": "<base64>",
 * …}}`.
 *
 * **Override point.** {@see BrowserRendererService} builds this class with `newInstance()`, so an application's
 * same-path subclass (`App\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot extends` this class) replaces it
 * for every page tool. The call is cut into one protected hook per concern:
 *
 * | Hook | Concern |
 * |---|---|
 * | {@see isRenderingAvailable()} | whether the page tools are offered and render at all |
 * | {@see buildLoadEndpoint()} | method-prefixed endpoint; a relative path goes through the application's Argus routing |
 * | {@see isExternalCall()} | external call (no application auth) or internal call (the application's own Argus auth) |
 * | {@see buildRequestHeaders()} | request headers (auth) |
 * | {@see buildRequestBody()} | request body (default {@see buildRendererBody()}, the renderer's camelCase body) |
 * | {@see findContentOfAnswer()} | the base64 PNG of an answer; null = no image |
 * | {@see findFailureReasonOfAnswer()} | the failure reason of an answer without an image (a `REASON_*` code) |
 * | {@see mapRendererResponse()} | the whole mapping (content, reason, optional page facts) when the two above do not fit |
 *
 * A subclass need not repeat `#[ArgusLoad]`: PHP attributes are not inherited, so {@see prepareCall()} applies the
 * package's cache (memory, ten minutes per URL and viewport, {@see applyPackageCacheSettingsUnlessDeclared()}) to a
 * subclass without its own attribute; the endpoint comes from {@see buildLoadEndpoint()} and the timeout from
 * `AI.Browser.renderer.screenshotTimeoutSeconds`. A subclass that declares `#[ArgusLoad]` keeps its own cache level and
 * lifetime.
 *
 * Everything above the call stays the package's whatever a subclass does: the refusal of a URL that is not public, the
 * cut into parts, the tall-window retry, the picture budget, the PNG check before the decoder, the tool and its skill.
 * A failure is never cached. Cached in memory for ten minutes per URL and viewport: a capture is megabytes of base64,
 * every part of one page reuses it.
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

    /** @var string|null Base URL of the stateless deployment set by {@see prepareCall()}; null = the configured one */
    protected ?string $baseUrl = null;

    protected ?string $callerToken = null;

    /**
     * Points this loader at its endpoint: {@see buildLoadEndpoint()} becomes the load endpoint, `$timeoutSeconds`
     * the time PHP waits. A refused or timed-out call answers null instead of throwing out of the load.
     * @param string|null $baseUrl absolute base URL of the stateless deployment; null = the configured one
     * @param string|null $callerToken the caller class's token
     * @param int $timeoutSeconds how long PHP waits for the answer
     * @return void
     */
    public function prepareCall(?string $baseUrl, ?string $callerToken, int $timeoutSeconds): void
    {
        $this->baseUrl = $baseUrl;
        $this->callerToken = $callerToken;
        $argusSettings = $this->getArgusSettings();
        $this->applyPackageCacheSettingsUnlessDeclared();
        $argusSettings->argusLoad->loadEndpoint = $this->buildLoadEndpoint($this->baseUrl);
        $argusSettings->argusLoad->timeout = (float)$timeoutSeconds;
        // the operations read the settings' timeout (copied from the attribute when the settings were built)
        $argusSettings->timeout = (float)$timeoutSeconds;
        $argusSettings->keepFulfilledCallsWhenOneIsRejected = true;
    }

    /**
     * The package's cache for a subclass without its own `#[ArgusLoad]` (attributes are not inherited, it would run
     * uncached and every part of a page would render again): memory, ten minutes. A subclass that declares the
     * attribute keeps its own settings.
     */
    protected function applyPackageCacheSettingsUnlessDeclared(): void
    {
        if (static::class === self::class || (new \ReflectionClass(static::class))->getAttributes(ArgusLoad::class) !== []) {
            return;
        }
        $argusSettings = $this->getArgusSettings();
        $argusSettings->argusLoad->cacheLevel = ArgusCache::CACHELEVEL_MEMORY;
        $argusSettings->argusLoad->cacheTtl = ArgusCache::CACHE_TTL_TEN_MINUTES;
    }

    /**
     * Hook: whether page rendering is available — the page tools are offered and render only then. Default: an
     * endpoint can be built from the configured stateless deployment ({@see buildLoadEndpoint()}). A subclass that
     * calls another service answers from that service's configuration.
     */
    public function isRenderingAvailable(): bool
    {
        return $this->buildLoadEndpoint($this->baseUrl) !== null;
    }

    /**
     * Hook: the method-prefixed load endpoint — `POST:https://…` is called directly, a relative `POST:/service/v1.0/route`
     * goes through the application's Argus routing. `$baseUrl` is the stateless deployment {@see prepareCall()} was
     * given (null = the configured one). Null when none can be built. Default: the deployment's base URL + the
     * screenshot route.
     */
    protected function buildLoadEndpoint(?string $baseUrl): ?string
    {
        $baseUrl ??= $this->getBrowserRendererService()->getBaseUrl();
        if ($baseUrl === null || $baseUrl === '') {
            return null;
        }
        return 'POST:' . rtrim($baseUrl, '/') . BrowserRendererContract::ROUTE_SCREENSHOT;
    }

    /**
     * Hook: true = an external call (no bearer token, no tracking or debug headers of the application — the default:
     * the renderer is a service outside the application); false = an internal call with the application's own Argus
     * auth.
     */
    protected function isExternalCall(): bool
    {
        return true;
    }

    /**
     * Hook: the request headers. Default: JSON and the caller token as {@see BrowserRendererContract::TOKEN_HEADER}.
     * @return array<string, string>
     */
    protected function buildRequestHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->callerToken !== null && $this->callerToken !== '') {
            $headers[BrowserRendererContract::TOKEN_HEADER] = $this->callerToken;
        }
        return $headers;
    }

    /**
     * Hook: the request body sent, built from {@see $url}, {@see $viewportWidth}, {@see $viewportHeight},
     * {@see $isMobile}, {@see $fullPage}. Default: {@see buildRendererBody()}. The capture must stay opaque and full page;
     * the image must be a PNG.
     * @return array<string, mixed>
     */
    protected function buildRequestBody(): array
    {
        return $this->buildRendererBody();
    }

    /**
     * The camelCase body of the renderer's stateless route.
     * @return array<string, mixed>
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

    /**
     * Hook: the base64 PNG of an answer; null when it carries none. `$callResponseData` is the decoded JSON (null when
     * the call got no answer; then `$apiOperation->rejectionReason` says why). Default: HTTP 200, `data.reason` OK and a
     * non-empty `data.content`.
     */
    protected function findContentOfAnswer(mixed $callResponseData, ?ArgusApiOperation $apiOperation): ?string
    {
        $data = is_object($callResponseData) && is_object($callResponseData->data ?? null) ? $callResponseData->data : null;
        $httpStatusCode = $apiOperation?->responseStatusCode
            ?? (is_object($callResponseData) && is_numeric($callResponseData->status ?? null) ? (int)$callResponseData->status : null);
        if ($data !== null && ($httpStatusCode === null || $httpStatusCode === 200) && ($data->reason ?? null) === BrowserRendererContract::REASON_OK
            && is_string($data->content ?? null) && $data->content !== ''
        ) {
            return $data->content;
        }
        return null;
    }

    /**
     * Hook: reads the answer onto this object — {@see BrowserPageScreenshot::$content} from {@see findContentOfAnswer()}
     * plus the page facts the answer has, else {@see BrowserPageScreenshot::$failureReason} from
     * {@see findFailureReasonOfAnswer()}. Override it only when content and reason do not cover the answer.
     */
    protected function mapRendererResponse(mixed $callResponseData, ?ArgusApiOperation $apiOperation): void
    {
        $content = $this->findContentOfAnswer($callResponseData, $apiOperation);
        if ($content !== null && $content !== '') {
            $this->content = $content;
            if (is_object($callResponseData) && is_object($callResponseData->data ?? null)) {
                $this->applyReportedPageFacts($callResponseData->data);
            }
            return;
        }
        $this->failureReason = static::findFailureReasonOfAnswer($callResponseData, $apiOperation);
    }

    /**
     * The optional facts the renderer reports beside the image: final URL, title, the page's HTTP status, the consent
     * banner handling. A field of another type stays null.
     */
    protected function applyReportedPageFacts(object $data): void
    {
        $this->finalUrl = is_string($data->finalUrl ?? null) && $data->finalUrl !== '' ? $data->finalUrl : null;
        $this->title = is_string($data->title ?? null) && $data->title !== '' ? $data->title : null;
        $this->pageHttpStatusCode = is_int($data->statusCode ?? null) ? $data->statusCode : null;
        $consent = is_object($data->consent ?? null) ? $data->consent : null;
        $this->consentPlatform = is_string($consent?->cmp ?? null) && $consent->cmp !== '' ? $consent->cmp : null;
        $this->consentAction = is_string($consent?->action ?? null) && $consent->action !== '' ? $consent->action : null;
    }

    /**
     * Hook: the failure code of an answer without an image: `data.reason` (or `reason`) when it is a code, upper-cased;
     * HTTP 429 → BUSY; for a call without an answer TIMEOUT when its reason says so, else FETCH_FAILED. A subclass maps
     * onto the `REASON_*` codes of {@see BrowserRendererContract} / {@see BrowserPageScreenshot}; the package calls it on
     * the loader's class, so an override is reached.
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
     * Hook: the failure code of a call that threw before any answer (a connection error, the client's timeout): TIMEOUT
     * when the message says so, else FETCH_FAILED. Called on the loader's class.
     */
    public static function findFailureReasonOfTransportError(Throwable $throwable): string
    {
        $message = $throwable->getMessage();
        return stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false || stripos($message, 'cURL error 28') !== false
            ? BrowserRendererContract::REASON_TIMEOUT
            : BrowserRendererContract::REASON_FETCH_FAILED;
    }

    /**
     * Reads the answer ({@see mapRendererResponse()}, on a reset object); a capture without content is a failure with a
     * reason (FETCH_FAILED when the hook named none) and is never cached.
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null,
    ): void {
        $this->content = null;
        $this->failureReason = null;
        $this->finalUrl = $this->title = $this->consentPlatform = $this->consentAction = null;
        $this->pageHttpStatusCode = null;
        $this->mapRendererResponse($callResponseData, $apiOperation);
        if (($this->content ?? '') !== '') {
            $this->failureReason = null;
            $this->postProcessLoadResponse($callResponseData);
            return;
        }
        $this->content = null;
        $this->failureReason ??= BrowserRendererContract::REASON_FETCH_FAILED;
        $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
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
     * The request: {@see buildRequestBody()}, {@see buildRequestHeaders()} and, for {@see isExternalCall()}, the
     * external-call flag. Null when no url is set, so the load stops instead of calling with an empty target.
     */
    protected function getLoadPayload(): ?array
    {
        if ($this->url === null || $this->url === '') {
            return null;
        }
        $loadPayload = ['body' => $this->buildRequestBody()];
        $requestHeaders = $this->buildRequestHeaders();
        if ($requestHeaders !== []) {
            $loadPayload['headers'] = $requestHeaders;
        }
        if ($this->isExternalCall()) {
            // a call outside the application's services: no bearer token, no tracking or debug headers
            $loadPayload[ArgusApiOperation::EXTERNAL_CALL] = true;
        }
        return $loadPayload;
    }

    protected function getBrowserRendererService(): BrowserRendererService
    {
        /** @var BrowserRendererService $browserRendererService */
        $browserRendererService = DDDService::instance()->getService(BrowserRendererService::class);
        return $browserRendererService;
    }
}
