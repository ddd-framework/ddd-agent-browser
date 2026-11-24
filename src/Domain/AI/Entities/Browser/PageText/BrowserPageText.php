<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\PageText;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The text of one web page as markdown, as the browser module read it (over HTTP or in the browser renderer).
 */
class BrowserPageText extends ValueObject
{
    public const string FETCHED_VIA_HTTP = 'http';
    public const string FETCHED_VIA_BROWSER = 'browser';

    /** @var string|null The page's title */
    public ?string $title = null;

    /** @var string The page text as markdown (empty when the page had none) */
    public string $markdown = '';

    /** @var string|null The URL the read ended on (after redirects) */
    public ?string $finalUrl = null;

    /** @var string|null How the page was read: `http` or `browser` */
    public ?string $fetchedVia = null;

    /**
     * The characters of the text without markdown syntax and whitespace: what decides whether a page is thin.
     * @return int
     */
    public function countTextCharacters(): int
    {
        $plainText = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $this->markdown);
        $plainText = (string)preg_replace('/[#*_`>|\-\s]+/u', '', $plainText);
        return mb_strlen($plainText);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(md5(($this->finalUrl ?? '') . '|' . ($this->fetchedVia ?? '') . '|' . $this->markdown));
    }
}
