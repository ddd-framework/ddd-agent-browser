<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services;

use DDD\Domain\Browser\Entities\Files\BrowserFileSinkContext;
use DDD\Domain\Browser\Entities\Files\BrowserFileToStore;
use DDD\Domain\Browser\Entities\Files\BrowserStoredFile;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The host's intake for files that leave the browser (exported assets, design token files). It is the same intake an
 * operator's upload goes through: type from the bytes, executable content refused (Office macros, PostScript, PDF
 * JavaScript, SVG scripts), malware scan; the file lands in the workspace as untrusted with its origin. Without an
 * implementation the tools that write files are not offered. One implementation per host (autoconfigured, tag
 * `browser.file_sink`; the first one wins).
 */
#[AutoconfigureTag(self::TAG)]
interface BrowserFileSink
{
    public const string TAG = 'browser.file_sink';

    /**
     * Stores the file or refuses it; never throws for a refused file.
     * @param BrowserFileToStore $browserFileToStore
     * @param BrowserFileSinkContext $browserFileSinkContext
     * @return BrowserStoredFile
     */
    public function storeFile(BrowserFileToStore $browserFileToStore, BrowserFileSinkContext $browserFileSinkContext): BrowserStoredFile;
}
