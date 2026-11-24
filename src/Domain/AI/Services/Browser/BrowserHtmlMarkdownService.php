<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser;

use DDD\Domain\AI\Entities\Browser\PageText\BrowserPageText;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\SafeXml;
use DDD\Infrastructure\Untrusted\UntrustedContentException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * A web page's HTML as markdown for a model: the main content (`main`, `[role=main]` or `article` when it carries
 * text, else `body`), headings, paragraphs, lists, links with absolute http(s) targets, tables, quotes and code; no
 * scripts, styles, navigation, footers, forms, frames or pictures. The HTML is foreign: it is parsed only through
 * {@see SafeXml::loadHtml()} and nothing of it is executed or followed.
 */
class BrowserHtmlMarkdownService extends Service
{
    /** @var string[] Elements whose whole subtree is dropped */
    public const array SKIPPED_ELEMENTS = [
        'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'nav', 'footer', 'aside', 'form', 'button', 'select', 'input', 'textarea', 'dialog', 'head', 'img', 'picture',
        'video', 'audio', 'map',
    ];

    /** @var string[] Elements that start a new block */
    public const array BLOCK_ELEMENTS = [
        'p', 'div', 'section', 'article', 'main', 'header', 'figure', 'figcaption', 'address', 'details', 'summary',
        'dl', 'dt', 'dd', 'hr', 'center',
    ];

    /** @var int Characters a main region needs to stand for the page instead of the body */
    public const int MAIN_REGION_MIN_CHARACTERS = 200;

    /**
     * The page text of `$html` as markdown; links are made absolute against `$baseUrl`.
     * @param string $html foreign HTML
     * @param string $baseUrl the URL the HTML was read from
     * @param int $maxBytes larger HTML is cut to this size before parsing
     * @return BrowserPageText
     */
    public function convertHtmlToPageText(string $html, string $baseUrl, int $maxBytes = SafeXml::DEFAULT_MAX_BYTES): BrowserPageText
    {
        $browserPageText = new BrowserPageText();
        $browserPageText->finalUrl = $baseUrl;
        if (strlen($html) > $maxBytes) {
            $html = substr($html, 0, $maxBytes);
        }
        try {
            $domDocument = SafeXml::loadHtml($html, $maxBytes);
        } catch (UntrustedContentException) {
            return $browserPageText;
        }
        $browserPageText->title = $this->findTitle($domDocument);
        $contentRoot = $this->findContentRoot($domDocument);
        if ($contentRoot === null) {
            return $browserPageText;
        }
        $browserPageText->markdown = $this->normalizeMarkdown($this->convertChildren($contentRoot, $baseUrl, 0));
        return $browserPageText;
    }

    /**
     * Plain text as a page text (a `text/plain` document).
     * @param string $text
     * @param string $baseUrl
     * @return BrowserPageText
     */
    public function convertPlainTextToPageText(string $text, string $baseUrl): BrowserPageText
    {
        $browserPageText = new BrowserPageText();
        $browserPageText->finalUrl = $baseUrl;
        $browserPageText->markdown = $this->normalizeMarkdown(mb_scrub($text, 'UTF-8'));
        return $browserPageText;
    }

    protected function findTitle(DOMDocument $domDocument): ?string
    {
        $titleElements = $domDocument->getElementsByTagName('title');
        if ($titleElements->length === 0) {
            return null;
        }
        $title = trim((string)preg_replace('/\s+/u', ' ', (string)$titleElements->item(0)?->textContent));
        return $title !== '' ? mb_substr($title, 0, 300) : null;
    }

    protected function findContentRoot(DOMDocument $domDocument): ?DOMElement
    {
        $domXPath = new DOMXPath($domDocument);
        foreach (['//main', '//*[@role="main"]', '//article'] as $mainRegionQuery) {
            $mainRegionNodes = $domXPath->query($mainRegionQuery);
            if ($mainRegionNodes === false) {
                continue;
            }
            foreach ($mainRegionNodes as $mainRegionNode) {
                if ($mainRegionNode instanceof DOMElement && mb_strlen(trim($mainRegionNode->textContent)) >= static::MAIN_REGION_MIN_CHARACTERS) {
                    return $mainRegionNode;
                }
            }
        }
        $bodyElement = $domDocument->getElementsByTagName('body')->item(0);
        if ($bodyElement instanceof DOMElement) {
            return $bodyElement;
        }
        return $domDocument->documentElement;
    }

    protected function convertChildren(DOMNode $parentNode, string $baseUrl, int $listDepth): string
    {
        $markdown = '';
        foreach ($parentNode->childNodes as $childNode) {
            $markdown .= $this->convertNode($childNode, $baseUrl, $listDepth);
        }
        return $markdown;
    }

    protected function convertNode(DOMNode $domNode, string $baseUrl, int $listDepth): string
    {
        if ($domNode instanceof DOMText) {
            return (string)preg_replace('/\s+/u', ' ', $domNode->wholeText);
        }
        if (!$domNode instanceof DOMElement) {
            return '';
        }
        $tagName = strtolower($domNode->tagName);
        if (in_array($tagName, static::SKIPPED_ELEMENTS, true) || $this->isHidden($domNode)) {
            return '';
        }
        switch ($tagName) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $headingText = $this->collapseInline($this->convertChildren($domNode, $baseUrl, $listDepth));
                return $headingText === '' ? '' : "\n\n" . str_repeat('#', (int)substr($tagName, 1)) . ' ' . $headingText . "\n\n";
            case 'br':
                return "\n";
            case 'hr':
                return "\n\n---\n\n";
            case 'strong':
            case 'b':
                return $this->wrapInline($this->convertChildren($domNode, $baseUrl, $listDepth), '**');
            case 'em':
            case 'i':
                return $this->wrapInline($this->convertChildren($domNode, $baseUrl, $listDepth), '*');
            case 'code':
                return $this->wrapInline($domNode->textContent, '`');
            case 'pre':
                return "\n\n```\n" . trim($domNode->textContent, "\n") . "\n```\n\n";
            case 'blockquote':
                $quotedText = trim($this->normalizeMarkdown($this->convertChildren($domNode, $baseUrl, $listDepth)));
                return $quotedText === '' ? '' : "\n\n> " . str_replace("\n", "\n> ", $quotedText) . "\n\n";
            case 'a':
                return $this->convertLink($domNode, $baseUrl, $listDepth);
            case 'ul':
            case 'ol':
                return $this->convertList($domNode, $baseUrl, $listDepth, $tagName === 'ol');
            case 'li':
                return "\n" . str_repeat('  ', $listDepth) . '- ' . trim($this->convertChildren($domNode, $baseUrl, $listDepth + 1)) . "\n";
            case 'table':
                return $this->convertTable($domNode, $baseUrl);
            default:
                $childrenMarkdown = $this->convertChildren($domNode, $baseUrl, $listDepth);
                return in_array($tagName, static::BLOCK_ELEMENTS, true) ? "\n\n" . $childrenMarkdown . "\n\n" : $childrenMarkdown;
        }
    }

    protected function convertLink(DOMElement $linkElement, string $baseUrl, int $listDepth): string
    {
        $linkText = $this->collapseInline($this->convertChildren($linkElement, $baseUrl, $listDepth));
        if ($linkText === '') {
            return '';
        }
        $absoluteUrl = $this->resolveHttpUrl(trim($linkElement->getAttribute('href')), $baseUrl);
        return $absoluteUrl === null ? $linkText : '[' . str_replace(['[', ']'], ['\\[', '\\]'], $linkText) . '](' . str_replace([' ', ')'], ['%20', '%29'], $absoluteUrl) . ')';
    }

    protected function convertList(DOMElement $listElement, string $baseUrl, int $listDepth, bool $isOrdered): string
    {
        $markdown = "\n";
        $itemNumber = 0;
        foreach ($listElement->childNodes as $childNode) {
            if (!$childNode instanceof DOMElement) {
                continue;
            }
            if (strtolower($childNode->tagName) !== 'li') {
                $markdown .= $this->convertNode($childNode, $baseUrl, $listDepth);
                continue;
            }
            if ($this->isHidden($childNode)) {
                continue;
            }
            $itemNumber++;
            $itemText = trim($this->normalizeMarkdown($this->convertChildren($childNode, $baseUrl, $listDepth + 1)));
            if ($itemText === '') {
                continue;
            }
            $marker = $isOrdered ? $itemNumber . '.' : '-';
            $markdown .= str_repeat('  ', $listDepth) . $marker . ' ' . str_replace("\n", "\n" . str_repeat('  ', $listDepth + 1), $itemText) . "\n";
        }
        return $listDepth === 0 ? "\n" . $markdown . "\n" : $markdown;
    }

    protected function convertTable(DOMElement $tableElement, string $baseUrl): string
    {
        $tableRows = [];
        $domXPath = new DOMXPath($tableElement->ownerDocument);
        $rowNodes = $domXPath->query('.//tr', $tableElement);
        foreach ($rowNodes === false ? [] : $rowNodes as $rowNode) {
            $cellTexts = [];
            foreach ($rowNode->childNodes as $cellNode) {
                if ($cellNode instanceof DOMElement && in_array(strtolower($cellNode->tagName), ['td', 'th'], true)) {
                    $cellTexts[] = str_replace('|', '\\|', $this->collapseInline($this->convertChildren($cellNode, $baseUrl, 0)));
                }
            }
            if ($cellTexts !== [] && implode('', $cellTexts) !== '') {
                $tableRows[] = $cellTexts;
            }
        }
        if ($tableRows === []) {
            return '';
        }
        $columnCount = max(array_map('count', $tableRows));
        $lines = [];
        foreach ($tableRows as $rowIndex => $cellTexts) {
            $lines[] = '| ' . implode(' | ', array_pad($cellTexts, $columnCount, '')) . ' |';
            if ($rowIndex === 0) {
                $lines[] = '|' . str_repeat(' --- |', $columnCount);
            }
        }
        return "\n\n" . implode("\n", $lines) . "\n\n";
    }

    protected function isHidden(DOMElement $domElement): bool
    {
        if ($domElement->hasAttribute('hidden') || strtolower($domElement->getAttribute('aria-hidden')) === 'true') {
            return true;
        }
        $style = strtolower(str_replace(' ', '', $domElement->getAttribute('style')));
        return str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden');
    }

    /**
     * An absolute http(s) URL for a link target; null for anchors, scripts, mail and anything not http(s).
     * @param string $href
     * @param string $baseUrl
     * @return string|null
     */
    public function resolveHttpUrl(string $href, string $baseUrl): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) === 1) {
            return preg_match('/^https?:\/\//i', $href) === 1 ? $href : null;
        }
        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $scheme = strtolower($baseParts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        $origin = $scheme . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $basePath = $baseParts['path'] ?? '/';
        $baseDirectory = str_ends_with($basePath, '/') ? $basePath : (string)preg_replace('#/[^/]*$#', '/', $basePath);
        if (str_starts_with($href, '?')) {
            return $origin . $basePath . $href;
        }
        return $origin . ($baseDirectory === '' ? '/' : $baseDirectory) . $href;
    }

    protected function wrapInline(string $text, string $marker): string
    {
        $collapsedText = $this->collapseInline($text);
        return $collapsedText === '' ? '' : $marker . $collapsedText . $marker . ' ';
    }

    protected function collapseInline(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    protected function normalizeMarkdown(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $trimmedLines = [];
        foreach ($lines as $line) {
            $line = rtrim((string)preg_replace('/(?<=\S) {2,}/u', ' ', $line));
            // the indent of a nested list item stays; any other leading space is a leftover of the markup
            $trimmedLines[] = preg_match('/^ +(-|\d+\.) /', $line) === 1 ? $line : ltrim($line);
        }
        $markdown = (string)preg_replace("/\n{3,}/", "\n\n", implode("\n", $trimmedLines));
        // two links side by side (a menu without separators) stay two links for a reader
        return trim((string)preg_replace('/\)\[(?=[^\]]*\]\(https?:)/', ') [', $markdown));
    }
}
