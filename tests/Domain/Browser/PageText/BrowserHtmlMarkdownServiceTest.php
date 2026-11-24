<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser\PageText;

use DDD\Domain\AI\Services\Browser\BrowserHtmlMarkdownService;
use PHPUnit\Framework\TestCase;

class BrowserHtmlMarkdownServiceTest extends TestCase
{
    public function testTheMainRegionBecomesMarkdownWithoutScriptsAndNavigation(): void
    {
        $html = <<<'HTML'
            <html><head><title> The  Title </title><script>alert(1)</script></head>
            <body>
              <nav><a href="/home">Home</a></nav>
              <main>
                <h2>Section</h2>
                <p>A paragraph with <strong>bold</strong>, <em>soft</em> and a <a href="/docs/start?x=1">relative link</a>.</p>
                <ul><li>First</li><li>Second <a href="javascript:evil()">script</a></li></ul>
                <ol><li>One</li><li>Two</li></ol>
                <table><tr><th>Name</th><th>Value</th></tr><tr><td>a</td><td>1</td></tr></table>
                <pre>code line</pre>
                <p hidden>hidden text</p>
                <style>.x{}</style>
                <p>Padding text so the main region is long enough to stand for the page, well over two hundred characters of real content in total here.</p>
              </main>
              <footer>Footer text</footer>
            </body></html>
            HTML;
        $browserPageText = (new BrowserHtmlMarkdownService())->convertHtmlToPageText($html, 'https://www.example.com/a/b.html');
        self::assertSame('The Title', $browserPageText->title);
        $markdown = $browserPageText->markdown;
        self::assertStringStartsWith('## Section', $markdown);
        self::assertStringContainsString('**bold**', $markdown);
        self::assertStringContainsString('*soft*', $markdown);
        self::assertStringContainsString('[relative link](https://www.example.com/docs/start?x=1)', $markdown);
        self::assertStringContainsString("- First\n- Second script", $markdown);
        self::assertStringContainsString("1. One\n2. Two", $markdown);
        self::assertStringContainsString("| Name | Value |\n| --- | --- |\n| a | 1 |", $markdown);
        self::assertStringContainsString("```\ncode line\n```", $markdown);
        foreach (['alert', 'Home', 'Footer', 'hidden text', 'javascript', '.x{}'] as $absentText) {
            self::assertStringNotContainsString($absentText, $markdown);
        }
    }

    public function testRelativeLinksResolveAgainstTheBaseUrl(): void
    {
        $browserHtmlMarkdownService = new BrowserHtmlMarkdownService();
        self::assertSame('https://example.com/a/c', $browserHtmlMarkdownService->resolveHttpUrl('c', 'https://example.com/a/b'));
        self::assertSame('https://cdn.example.com/x', $browserHtmlMarkdownService->resolveHttpUrl('//cdn.example.com/x', 'https://example.com/'));
        self::assertSame('http://example.com:8080/root', $browserHtmlMarkdownService->resolveHttpUrl('/root', 'http://example.com:8080/a/'));
        self::assertNull($browserHtmlMarkdownService->resolveHttpUrl('mailto:a@b.c', 'https://example.com/'));
        self::assertNull($browserHtmlMarkdownService->resolveHttpUrl('#top', 'https://example.com/'));
    }

    public function testAnEntityDeclarationIsNeverExpanded(): void
    {
        $html = '<!DOCTYPE html [<!ENTITY secret SYSTEM "file:///etc/passwd">]><html><body><p>&secret; text</p></body></html>';
        $browserPageText = (new BrowserHtmlMarkdownService())->convertHtmlToPageText($html, 'https://example.com/');
        self::assertStringNotContainsString('root:', $browserPageText->markdown);
    }

    public function testPlainTextStaysText(): void
    {
        $browserPageText = (new BrowserHtmlMarkdownService())->convertPlainTextToPageText("Line one\r\n\r\n\r\nLine two", 'https://example.com/t.txt');
        self::assertSame("Line one\n\nLine two", $browserPageText->markdown);
    }
}
