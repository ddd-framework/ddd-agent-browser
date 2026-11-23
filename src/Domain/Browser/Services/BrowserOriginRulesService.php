<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services;

use DDD\Domain\Browser\Entities\Origins\BrowserOriginTexts;
use DDD\Infrastructure\Services\Service;

/**
 * Where a URL or a text the model wants to send may come from without the operator's yes. A URL runs when it stands
 * in an operator message, is the bare start page of a host an operator message names, or appeared in full (host, path,
 * query) in a result. A typed text runs when it stands in an operator message. Anything else needs the operator's
 * consent; without an operator (schedule, sub-agent) it is refused.
 */
class BrowserOriginRulesService extends Service
{
    /**
     * @param string $url
     * @param BrowserOriginTexts $browserOriginTexts
     * @return bool
     */
    public function isUrlOfKnownOrigin(string $url, BrowserOriginTexts $browserOriginTexts): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $urlForms = $this->buildUrlForms($url);
        foreach ($browserOriginTexts->operatorTexts as $operatorText) {
            if ($this->containsOneOf($operatorText, $urlForms)) {
                return true;
            }
        }
        if ($this->isBareStartPage($url)) {
            $host = $this->extractHost($url);
            foreach ($browserOriginTexts->operatorTexts as $operatorText) {
                if ($host !== null && $this->namesHost($operatorText, $host)) {
                    return true;
                }
            }
        }
        foreach ($browserOriginTexts->resultTexts as $resultText) {
            if ($this->containsOneOf($resultText, $urlForms)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $text
     * @param BrowserOriginTexts $browserOriginTexts
     * @return bool
     */
    public function isTextFromOperator(string $text, BrowserOriginTexts $browserOriginTexts): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }
        foreach ($browserOriginTexts->operatorTexts as $operatorText) {
            if (str_contains($operatorText, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The host of a URL, lower case, without a leading `www.`; null for a URL without one.
     * @param string $url
     * @return string|null
     */
    public function extractHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param string $url
     * @return bool true for `https://host` or `https://host/` without query or fragment
     */
    public function isBareStartPage(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        return ($path === null || $path === '' || $path === '/')
            && parse_url($url, PHP_URL_QUERY) === null
            && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    /**
     * The spellings of a URL a text may hold it in: as is, without a trailing slash, HTML- and JSON-escaped.
     * @param string $url
     * @return string[]
     */
    protected function buildUrlForms(string $url): array
    {
        $urlForms = [$url, htmlspecialchars($url, ENT_QUOTES), str_replace('/', '\\/', $url)];
        $urlWithoutTrailingSlash = rtrim($url, '/');
        if ($urlWithoutTrailingSlash !== $url && parse_url($url, PHP_URL_QUERY) === null) {
            $urlForms[] = $urlWithoutTrailingSlash;
        }
        return array_values(array_unique($urlForms));
    }

    /**
     * @param string $text
     * @param string[] $needles
     * @return bool
     */
    protected function containsOneOf(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && $this->containsUrl($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** A URL inside a text, not as the beginning of a longer URL (`/a` must not match `/ab`) */
    protected function containsUrl(string $text, string $url): bool
    {
        // the URL ends where the text ends, at a space or quote or bracket, or at sentence punctuation before a space
        return preg_match('/' . preg_quote($url, '/') . '(?=$|[\s"\'<>)\]},;|]|[.,!?:](?:\s|$))/u', $text) === 1;
    }

    /** Whether a text names a host (as a word: `example.com`, `www.example.com`, or inside a URL) */
    protected function namesHost(string $text, string $host): bool
    {
        return preg_match('/(?<![A-Za-z0-9.-])(www\.)?' . preg_quote($host, '/') . '(?![A-Za-z0-9-])/i', $text) === 1;
    }
}
