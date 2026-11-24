<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Infrastructure\Services\GuardedHttpFetchService;
use DDD\Infrastructure\Untrusted\GuardedHttpFetchResult;

/** A guarded fetch that answers from a script by URL and records the URLs asked; nothing reaches the network */
class ScriptedGuardedHttpFetchService extends GuardedHttpFetchService
{
    /** @var GuardedHttpFetchResult[] Answers by URL */
    public array $resultsByUrl = [];

    /** @var string[] The URLs fetched */
    public array $fetchedUrls = [];

    public function fetch(
        string $url,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
        array $allowedContentTypes = [],
        array $requestHeaders = []
    ): GuardedHttpFetchResult {
        $this->fetchedUrls[] = $url;
        if (isset($this->resultsByUrl[$url])) {
            return $this->resultsByUrl[$url];
        }
        $guardedHttpFetchResult = new GuardedHttpFetchResult();
        $guardedHttpFetchResult->requestedUrl = $url;
        $guardedHttpFetchResult->finalUrl = $url;
        $guardedHttpFetchResult->refusalReason = GuardedHttpFetchResult::REASON_FETCH_FAILED;
        return $guardedHttpFetchResult;
    }

    public static function createHtmlResult(string $url, string $html, int $statusCode = 200, string $contentType = 'text/html; charset=utf-8'): GuardedHttpFetchResult
    {
        $guardedHttpFetchResult = new GuardedHttpFetchResult();
        $guardedHttpFetchResult->requestedUrl = $url;
        $guardedHttpFetchResult->finalUrl = $url;
        $guardedHttpFetchResult->statusCode = $statusCode;
        $guardedHttpFetchResult->contentType = $contentType;
        $guardedHttpFetchResult->body = $html;
        return $guardedHttpFetchResult;
    }
}
