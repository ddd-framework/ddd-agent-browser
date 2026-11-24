<?php

declare(strict_types=1);

/**
 * Agent browser module: the page text reader of the web research (`BrowserWebPageTextSourceService`, the
 * `WebPageTextSource` of mgamadeus/ddd-agents >= 0.3.1). While it is enabled, the agent's `getWebPageAsMarkdown` reads
 * pages through it instead of the url-to-markdown endpoint: over HTTP first (guarded fetch), in the browser renderer's
 * session deployment when the HTTP text is thin, a bot challenge or no page at all.
 *
 * A config file replaces the module's default file as a whole: keep every key.
 */
return [
    // read the web research's pages through this module (true) or leave them to the url-to-markdown endpoint (false)
    'enabled' => false,
    // auto = HTTP first, the browser for a thin page; always = the browser first (HTTP when the renderer cannot run);
    // never = HTTP only
    'render' => 'auto',
    // a page whose HTTP text has fewer characters than this is read again in the browser (render auto)
    'thinPageMinCharacters' => 600,
    // seconds one HTTP read may take, per redirect hop
    'httpTimeoutSeconds' => 10,
    // bytes of HTML read over HTTP; a longer document is cut there
    'httpMaxBytes' => 3145728,
    // redirects followed over HTTP (each hop is guarded again)
    'httpMaxRedirects' => 4,
    // characters of page text read from the browser per snapshot page, and the number of pages read at most
    'browserCharactersPerPage' => 20000,
    'browserMaxPages' => 3,
    // characters of markdown kept per page; a longer text is cut with a notice
    'maxMarkdownCharacters' => 60000,
    // seconds a successful read is cached (per URL and render mode); 0 = no cache
    'cacheTtlSeconds' => 3600,
];
