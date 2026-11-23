<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services;

use DDD\Domain\Browser\Entities\Files\BrowserFileSinkContext;
use DDD\Domain\Browser\Entities\Files\BrowserFileToStore;
use DDD\Domain\Browser\Entities\Files\BrowserStoredFile;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * Hands files to the host's {@see BrowserFileSink} and reads the type of foreign bytes (the type a server claimed is
 * never used).
 */
class BrowserFileSinksService extends Service
{
    /** @var iterable<BrowserFileSink> */
    protected iterable $fileSinks = [];

    /** @param iterable<BrowserFileSink> $fileSinks */
    #[Required]
    public function setFileSinks(
        #[AutowireIterator(BrowserFileSink::TAG)]
        iterable $fileSinks
    ): void {
        $this->fileSinks = $fileSinks;
    }

    public function findFileSink(): ?BrowserFileSink
    {
        foreach ($this->fileSinks as $browserFileSink) {
            return $browserFileSink;
        }
        return null;
    }

    public function hasFileSink(): bool
    {
        return $this->findFileSink() !== null;
    }

    /**
     * Stores the file through the host's sink; a missing sink or a throwing sink is a refusal.
     * @param BrowserFileToStore $browserFileToStore
     * @param BrowserFileSinkContext $browserFileSinkContext
     * @return BrowserStoredFile
     */
    public function storeFile(BrowserFileToStore $browserFileToStore, BrowserFileSinkContext $browserFileSinkContext): BrowserStoredFile
    {
        $browserFileSink = $this->findFileSink();
        if ($browserFileSink === null) {
            return BrowserStoredFile::refused('no file sink is set up');
        }
        try {
            return $browserFileSink->storeFile($browserFileToStore, $browserFileSinkContext);
        } catch (Throwable $throwable) {
            $this->getLogger()->warning('[Browser] file sink failed', ['reason' => $throwable::class . ': ' . mb_substr($throwable->getMessage(), 0, 300)]);
            return BrowserStoredFile::refused('the file could not be stored');
        }
    }

    /**
     * The type of foreign bytes read from the bytes: raster images by their magic bytes, SVG and stylesheets by their
     * text, fonts by their signature, anything else as libmagic sees it. Never the claimed type.
     * @param string $bytes
     * @param string|null $claimedMimeType used only to tell a stylesheet from other plain text
     * @return string
     */
    public function detectContentType(string $bytes, ?string $claimedMimeType = null): string
    {
        $rasterMimeType = RasterImageDecoder::detectMimeType($bytes);
        if ($rasterMimeType !== null) {
            return $rasterMimeType;
        }
        $head = substr($bytes, 0, 4);
        if ($head === 'wOF2') {
            return 'font/woff2';
        }
        if ($head === 'wOFF') {
            return 'font/woff';
        }
        if ($head === "\x00\x01\x00\x00" || $head === 'OTTO' || $head === 'true') {
            return $head === 'OTTO' ? 'font/otf' : 'font/ttf';
        }
        $textHead = ltrim(substr($bytes, 0, 2048));
        if (preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE svg[^>]*>\s*)?<svg[\s>]/is', $textHead) === 1) {
            return 'image/svg+xml';
        }
        $detectedMimeType = RasterImageDecoder::describeContentType($bytes);
        if ($detectedMimeType === 'text/plain' && UntrustedContentHeaders::normalizeContentType((string)$claimedMimeType) === 'text/css') {
            return 'text/css';
        }
        return $detectedMimeType === 'empty' || $detectedMimeType === 'unknown' ? 'application/octet-stream' : $detectedMimeType;
    }

    /**
     * A file name safe for any store: the last path segment of the given name, letters, digits, dot, dash and underscore.
     * @param string|null $fileName
     * @param string $fallbackFileName
     * @return string
     */
    public function sanitizeFileName(?string $fileName, string $fallbackFileName): string
    {
        $baseName = basename(str_replace('\\', '/', (string)$fileName));
        $safeName = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName), '.-');
        return $safeName === '' ? $fallbackFileName : mb_substr($safeName, 0, 120);
    }
}
