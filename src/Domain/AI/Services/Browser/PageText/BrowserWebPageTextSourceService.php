<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser\PageText;

use DDD\Domain\AI\Entities\Browser\PageText\BrowserPageText;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionRequest;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Domain\AI\Services\Browser\BrowserHtmlMarkdownService;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Domain\AI\Services\WebSearch\WebPageTextSource;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\GuardedHttpFetchService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\GuardedHttpFetchResult;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Throwable;

/**
 * The page reader of the agent's web research (`WebPageTextSource` of mgamadeus/ddd-agents >= 0.3.1), switched on by
 * `AI.Browser.pageText.enabled`. Per page, by `AI.Browser.pageText.render`:
 *
 * - `auto`: over HTTP first ({@see GuardedHttpFetchService}: public addresses only, every redirect hop guarded, a byte
 *   and time cap, HTML and plain text only); a page whose text is thin, a bot challenge or no page at all is read
 *   again in the browser renderer's session deployment (navigate, the main region as text, close); the longer text
 *   wins;
 * - `always`: the browser first, HTTP when the renderer cannot run (not configured, busy, a renderer fault);
 * - `never`: HTTP only.
 *
 * Each page names its reader on {@see WebPageMarkdown::$fetchedVia} (`http` or `browser`). A page failure is an
 * element with `ok` false and a reason, never an exception. Successful reads are cached for `cacheTtlSeconds`.
 */
class BrowserWebPageTextSourceService extends Service implements WebPageTextSource
{
    public const string RENDER_AUTO = 'auto';
    public const string RENDER_ALWAYS = 'always';
    public const string RENDER_NEVER = 'never';

    /** @var string[] */
    public const array RENDER_MODES = [self::RENDER_AUTO, self::RENDER_ALWAYS, self::RENDER_NEVER];

    /** @var string[] The document types read over HTTP */
    public const array HTTP_CONTENT_TYPES = ['text/html', 'application/xhtml+xml', 'text/plain'];

    /** @var int Seconds a browser read needs at least; with less time left the browser is not started */
    public const int MINIMUM_BROWSER_SECONDS = 20;

    protected const string CACHE_KEY_PREFIX = 'browser.pageText.';

    public function isAvailable(): bool
    {
        return (bool)$this->readPageTextSetting('enabled', false);
    }

    /**
     * @param string[] $urls
     */
    public function getWebPagesAsMarkdown(array $urls, bool $noCache = false, ?float $timeoutSeconds = null): WebPageMarkdowns
    {
        $deadline = $timeoutSeconds !== null ? microtime(true) + $timeoutSeconds : null;
        $webPageMarkdowns = WebPageMarkdowns::newInstance();
        foreach ($urls as $url) {
            $webPageMarkdown = $this->getWebPageAsMarkdown((string)$url, $noCache, $deadline);
            $webPageMarkdowns->add($webPageMarkdown);
        }
        return $webPageMarkdowns;
    }

    /**
     * One page, read within `$deadline` (a microtime; null = no bound of the caller).
     * @param string $url
     * @param bool $noCache
     * @param float|null $deadline
     * @return WebPageMarkdown
     */
    public function getWebPageAsMarkdown(string $url, bool $noCache = false, ?float $deadline = null): WebPageMarkdown
    {
        $renderMode = $this->getRenderMode();
        $cacheKey = static::CACHE_KEY_PREFIX . md5(WebPageMarkdown::canonicalUrlKey($url) . '|' . $renderMode);
        $cacheTtlSeconds = (int)$this->readPageTextSetting('cacheTtlSeconds', 3600);
        if (!$noCache && $cacheTtlSeconds > 0) {
            $cachedWebPageMarkdown = $this->readCachedPage($cacheKey);
            if ($cachedWebPageMarkdown !== null) {
                $cachedWebPageMarkdown->url = $url;
                return $cachedWebPageMarkdown;
            }
        }
        $webPageMarkdown = $this->readWebPage($url, $renderMode, $deadline);
        $webPageMarkdown->url = $url;
        if ($cacheTtlSeconds > 0 && ($webPageMarkdown->ok ?? false)) {
            $this->writeCachedPage($cacheKey, $webPageMarkdown, $cacheTtlSeconds);
        }
        return $webPageMarkdown;
    }

    public function getRenderMode(): string
    {
        $renderMode = $this->readPageTextSetting('render', null);
        return is_string($renderMode) && in_array($renderMode, static::RENDER_MODES, true) ? $renderMode : self::RENDER_AUTO;
    }

    protected function readWebPage(string $url, string $renderMode, ?float $deadline): WebPageMarkdown
    {
        if ($renderMode === self::RENDER_ALWAYS) {
            $browserWebPageMarkdown = $this->readWebPageInBrowser($url, $deadline);
            if ($browserWebPageMarkdown !== null) {
                return $browserWebPageMarkdown;
            }
            return $this->readWebPageOverHttp($url, $deadline);
        }
        $httpWebPageMarkdown = $this->readWebPageOverHttp($url, $deadline);
        if ($renderMode === self::RENDER_NEVER || !$this->needsBrowserRead($httpWebPageMarkdown)) {
            return $httpWebPageMarkdown;
        }
        $browserWebPageMarkdown = $this->readWebPageInBrowser($url, $deadline);
        return $this->chooseBetterRead($httpWebPageMarkdown, $browserWebPageMarkdown);
    }

    /**
     * Whether an HTTP read leaves the page to the browser: no page, a bot challenge, or fewer text characters than
     * `thinPageMinCharacters`. A host that refused the address (blocked, unresolvable) is never retried.
     */
    public function needsBrowserRead(WebPageMarkdown $httpWebPageMarkdown): bool
    {
        if (!($httpWebPageMarkdown->ok ?? false)) {
            return !in_array($httpWebPageMarkdown->reason, WebPageMarkdown::REASONS_NO_TOOL_CAN_REACH, true);
        }
        $botChallengeProbe = clone $httpWebPageMarkdown;
        $botChallengeProbe->downgradeBotChallengeToFailure();
        if (!($botChallengeProbe->ok ?? false)) {
            return true;
        }
        $browserPageText = new BrowserPageText();
        $browserPageText->markdown = (string)$httpWebPageMarkdown->markdown;
        return $browserPageText->countTextCharacters() < (int)$this->readPageTextSetting('thinPageMinCharacters', 600);
    }

    /**
     * The read to hand out: a successful browser read when it has more text than the HTTP read (or the HTTP read
     * failed), else the HTTP read.
     */
    protected function chooseBetterRead(WebPageMarkdown $httpWebPageMarkdown, ?WebPageMarkdown $browserWebPageMarkdown): WebPageMarkdown
    {
        if ($browserWebPageMarkdown === null || !($browserWebPageMarkdown->ok ?? false)) {
            if (!($httpWebPageMarkdown->ok ?? false) && $browserWebPageMarkdown !== null
                && $httpWebPageMarkdown->reason === WebPageMarkdown::REASON_FETCH_FAILED
            ) {
                // the browser's reason (a bot challenge, a timeout) says more than the HTTP read's generic failure
                return $browserWebPageMarkdown;
            }
            return $httpWebPageMarkdown;
        }
        if (!($httpWebPageMarkdown->ok ?? false)) {
            return $browserWebPageMarkdown;
        }
        $botChallengeProbe = clone $httpWebPageMarkdown;
        $botChallengeProbe->downgradeBotChallengeToFailure();
        if (!($botChallengeProbe->ok ?? false)) {
            return $browserWebPageMarkdown;
        }
        return mb_strlen((string)$browserWebPageMarkdown->markdown) > mb_strlen((string)$httpWebPageMarkdown->markdown)
            ? $browserWebPageMarkdown
            : $httpWebPageMarkdown;
    }

    /**
     * The page over HTTP (guarded fetch, HTML or plain text) as markdown.
     */
    public function readWebPageOverHttp(string $url, ?float $deadline): WebPageMarkdown
    {
        $timeoutSeconds = (float)$this->readPageTextSetting('httpTimeoutSeconds', 10);
        if ($deadline !== null) {
            $timeoutSeconds = min($timeoutSeconds, $deadline - microtime(true));
            if ($timeoutSeconds < 1.0) {
                return WebPageMarkdown::fromRefusedUrl($url, WebPageMarkdown::REASON_TIMEOUT);
            }
        }
        $maxBytes = max(65536, (int)$this->readPageTextSetting('httpMaxBytes', 3145728));
        $guardedHttpFetchResult = $this->getGuardedHttpFetchService()->fetch(
            $url,
            $maxBytes,
            $timeoutSeconds,
            max(0, (int)$this->readPageTextSetting('httpMaxRedirects', 4)),
            static::HTTP_CONTENT_TYPES,
            ['Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9', 'Accept-Language' => 'en,de;q=0.8']
        );
        if (!$guardedHttpFetchResult->isSuccessful()) {
            return WebPageMarkdown::fromRefusedUrl($url, $this->mapHttpFailureReason($guardedHttpFetchResult));
        }
        $contentType = UntrustedContentHeaders::normalizeContentType($guardedHttpFetchResult->contentType);
        $browserHtmlMarkdownService = $this->getBrowserHtmlMarkdownService();
        $browserPageText = $contentType === 'text/plain'
            ? $browserHtmlMarkdownService->convertPlainTextToPageText($guardedHttpFetchResult->body, $guardedHttpFetchResult->finalUrl)
            : $browserHtmlMarkdownService->convertHtmlToPageText($guardedHttpFetchResult->body, $guardedHttpFetchResult->finalUrl, $maxBytes);
        $browserPageText->fetchedVia = BrowserPageText::FETCHED_VIA_HTTP;
        return $this->buildWebPageMarkdown($url, $browserPageText);
    }

    /**
     * The page in the browser renderer's session deployment: a fresh session navigates to it, the main region is read
     * as text page by page, the session is closed. Null when the renderer cannot run here (no session endpoint, too
     * little time left, busy, a renderer fault): the caller keeps its HTTP read.
     */
    public function readWebPageInBrowser(string $url, ?float $deadline): ?WebPageMarkdown
    {
        $browserRendererService = $this->getBrowserRendererService();
        if (!$browserRendererService->isSessionEndpointConfigured()) {
            return null;
        }
        if ($deadline !== null && $deadline - microtime(true) < static::MINIMUM_BROWSER_SECONDS) {
            return null;
        }
        $navigateRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_NAVIGATE);
        $navigateRequest->url = $url;
        $navigateRequest->cookieBanners = 'reject';
        $sessionKey = null;
        try {
            $navigateAnswer = $browserRendererService->runSessionAction($navigateRequest);
            $sessionKey = $navigateAnswer->sessionKey;
            if ($navigateAnswer->isRendererFault()) {
                return null;
            }
            if ($navigateAnswer->reason !== BrowserRendererContract::REASON_OK) {
                return WebPageMarkdown::fromRefusedUrl($url, $this->mapRendererFailureReason($navigateAnswer->reason));
            }
            $browserPageText = new BrowserPageText();
            $browserPageText->fetchedVia = BrowserPageText::FETCHED_VIA_BROWSER;
            $browserPageText->title = $navigateAnswer->title;
            $browserPageText->finalUrl = $navigateAnswer->finalUrl ?? $url;
            $browserPageText->markdown = $this->readPageTextOfSession($browserRendererService, (string)$sessionKey, $navigateAnswer->tab, $deadline);
            return $this->buildWebPageMarkdown($url, $browserPageText);
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[BrowserPageText] the browser read failed, the HTTP read stays', ['url' => $url, 'reason' => $throwable->getMessage()]);
            return null;
        } finally {
            if ($sessionKey !== null && $sessionKey !== '') {
                $this->closeSession($browserRendererService, $sessionKey);
            }
        }
    }

    protected function readPageTextOfSession(BrowserRendererService $browserRendererService, string $sessionKey, ?string $tab, ?float $deadline): string
    {
        $charactersPerPage = min(20000, max(500, (int)$this->readPageTextSetting('browserCharactersPerPage', 20000)));
        $maxPages = max(1, (int)$this->readPageTextSetting('browserMaxPages', 3));
        $pageTexts = [];
        $cursor = null;
        for ($pageIndex = 0; $pageIndex < $maxPages; $pageIndex++) {
            if ($pageIndex > 0 && $deadline !== null && $deadline - microtime(true) < 5) {
                break;
            }
            $snapshotRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_SNAPSHOT, $sessionKey);
            $snapshotRequest->tab = $tab;
            $snapshotRequest->mode = 'text';
            $snapshotRequest->maxChars = $charactersPerPage;
            $snapshotRequest->cursor = $cursor;
            $snapshotAnswer = $browserRendererService->runSessionAction($snapshotRequest);
            if (!$snapshotAnswer->isOk() || $snapshotAnswer->snapshotText === null) {
                break;
            }
            $pageTexts[] = static::stripContinuationLine($snapshotAnswer->snapshotText);
            if ($snapshotAnswer->nextCursor === null) {
                break;
            }
            $cursor = $snapshotAnswer->nextCursor;
        }
        return trim(implode("\n", $pageTexts));
    }

    /** The snapshot's own last line `… N more lines (cursor=…)` is an instruction for an agent, not page text. */
    public static function stripContinuationLine(string $snapshotText): string
    {
        return (string)preg_replace('/\n?… \d+ more lines \(cursor=\d+\)\s*$/u', '', $snapshotText);
    }

    protected function closeSession(BrowserRendererService $browserRendererService, string $sessionKey): void
    {
        try {
            $browserRendererService->runSessionAction(BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_CLOSE, $sessionKey));
        } catch (Throwable) {
            // the renderer ends an idle session by itself
        }
    }

    protected function buildWebPageMarkdown(string $url, BrowserPageText $browserPageText): WebPageMarkdown
    {
        $webPageMarkdown = WebPageMarkdown::newInstance();
        $webPageMarkdown->url = $url;
        $webPageMarkdown->fetchedVia = $browserPageText->fetchedVia;
        $webPageMarkdown->title = $browserPageText->title;
        $markdown = $browserPageText->markdown;
        if (trim($markdown) === '') {
            $webPageMarkdown->ok = false;
            $webPageMarkdown->reason = WebPageMarkdown::REASON_NO_CONTENT;
            return $webPageMarkdown;
        }
        $maxMarkdownCharacters = max(1000, (int)$this->readPageTextSetting('maxMarkdownCharacters', 60000));
        if (mb_strlen($markdown) > $maxMarkdownCharacters) {
            $markdown = mb_substr($markdown, 0, $maxMarkdownCharacters) . "\n\n… (the page text is cut here)";
        }
        $webPageMarkdown->ok = true;
        $webPageMarkdown->markdown = $markdown;
        return $webPageMarkdown;
    }

    public function mapHttpFailureReason(GuardedHttpFetchResult $guardedHttpFetchResult): string
    {
        return match ($guardedHttpFetchResult->refusalReason) {
            GuardedHttpFetchResult::REASON_BLOCKED => WebPageMarkdown::REASON_BLOCKED,
            GuardedHttpFetchResult::REASON_UNRESOLVABLE => WebPageMarkdown::REASON_UNRESOLVABLE,
            GuardedHttpFetchResult::REASON_TIMEOUT => WebPageMarkdown::REASON_TIMEOUT,
            GuardedHttpFetchResult::REASON_UNSUPPORTED_TYPE => WebPageMarkdown::REASON_UNSUPPORTED_TYPE,
            GuardedHttpFetchResult::REASON_TOO_MANY_REDIRECTS => WebPageMarkdown::REASON_TOO_MANY_REDIRECTS,
            default => WebPageMarkdown::REASON_FETCH_FAILED,
        };
    }

    public function mapRendererFailureReason(?string $rendererReason): string
    {
        return match ($rendererReason) {
            BrowserRendererContract::REASON_BLOCKED => WebPageMarkdown::REASON_BLOCKED,
            BrowserRendererContract::REASON_BOT_CHALLENGE => WebPageMarkdown::REASON_BOT_CHALLENGE,
            BrowserRendererContract::REASON_TIMEOUT => WebPageMarkdown::REASON_TIMEOUT,
            BrowserRendererContract::REASON_UNRESOLVABLE => WebPageMarkdown::REASON_UNRESOLVABLE,
            BrowserRendererContract::REASON_TOO_MANY_REDIRECTS => WebPageMarkdown::REASON_TOO_MANY_REDIRECTS,
            default => WebPageMarkdown::REASON_FETCH_FAILED,
        };
    }

    /**
     * One setting of `AI.Browser.pageText` (config/app/AI/Browser/pageText.php); `$default` when it is not set.
     * @param string $settingName
     * @param mixed $default
     * @return mixed
     */
    protected function readPageTextSetting(string $settingName, mixed $default): mixed
    {
        return Config::get('AI.Browser.pageText.' . $settingName) ?? $default;
    }

    protected function readCachedPage(string $cacheKey): ?WebPageMarkdown
    {
        try {
            $cachedPage = Cache::instance()->get($cacheKey);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($cachedPage) || !is_string($cachedPage['markdown'] ?? null)) {
            return null;
        }
        $webPageMarkdown = WebPageMarkdown::newInstance();
        $webPageMarkdown->ok = true;
        $webPageMarkdown->markdown = $cachedPage['markdown'];
        $webPageMarkdown->title = is_string($cachedPage['title'] ?? null) ? $cachedPage['title'] : null;
        $webPageMarkdown->fetchedVia = is_string($cachedPage['fetchedVia'] ?? null) ? $cachedPage['fetchedVia'] : null;
        return $webPageMarkdown;
    }

    protected function writeCachedPage(string $cacheKey, WebPageMarkdown $webPageMarkdown, int $cacheTtlSeconds): void
    {
        try {
            // plain strings only: the cache never holds an object built from foreign bytes
            Cache::instance()->set($cacheKey, [
                'markdown' => (string)$webPageMarkdown->markdown,
                'title' => $webPageMarkdown->title,
                'fetchedVia' => $webPageMarkdown->fetchedVia,
            ], $cacheTtlSeconds);
        } catch (Throwable) {
            // a cache that cannot write costs a read next time, nothing else
        }
    }

    protected function getGuardedHttpFetchService(): GuardedHttpFetchService
    {
        /** @var GuardedHttpFetchService $guardedHttpFetchService */
        $guardedHttpFetchService = DDDService::instance()->getService(GuardedHttpFetchService::class);
        return $guardedHttpFetchService;
    }

    protected function getBrowserHtmlMarkdownService(): BrowserHtmlMarkdownService
    {
        /** @var BrowserHtmlMarkdownService $browserHtmlMarkdownService */
        $browserHtmlMarkdownService = DDDService::instance()->getService(BrowserHtmlMarkdownService::class);
        return $browserHtmlMarkdownService;
    }

    protected function getBrowserRendererService(): BrowserRendererService
    {
        /** @var BrowserRendererService $browserRendererService */
        $browserRendererService = DDDService::instance()->getService(BrowserRendererService::class);
        return $browserRendererService;
    }
}
