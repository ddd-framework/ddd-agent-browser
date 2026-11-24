<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Domain\Browser;

use DDD\Domain\AI\Entities\Browser\Files\BrowserFileSinkContext;
use DDD\Domain\AI\Entities\Browser\Files\BrowserFileToStore;
use DDD\Domain\AI\Services\Browser\BrowserFileSinksService;
use PHPUnit\Framework\TestCase;

/** The type of foreign bytes comes from the bytes; without a sink nothing is stored */
class BrowserFileSinksServiceTest extends TestCase
{
    public function testTheTypeIsReadFromTheBytesNeverFromTheClaim(): void
    {
        $browserFileSinksService = new BrowserFileSinksService();
        $pngBytes = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAC0lEQVR4nGNgwAQAABQAAX3+Hu4AAAAASUVORK5CYII=', true);
        self::assertSame('image/png', $browserFileSinksService->detectContentType($pngBytes, 'text/html'));
        self::assertSame('image/svg+xml', $browserFileSinksService->detectContentType('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/png'));
        self::assertSame('font/woff2', $browserFileSinksService->detectContentType('wOF2' . str_repeat("\x00", 40), null));
        self::assertSame('text/css', $browserFileSinksService->detectContentType('body { color: #005e3f; }', 'text/css; charset=utf-8'));
        self::assertNotSame('text/css', $browserFileSinksService->detectContentType('<html><script>alert(1)</script></html>', 'text/css'), 'HTML is never a stylesheet because a server said so');
    }

    public function testAFileNameKeepsOnlySafeCharacters(): void
    {
        $browserFileSinksService = new BrowserFileSinksService();
        self::assertSame('logo.svg', $browserFileSinksService->sanitizeFileName('../../etc/logo.svg', 'asset'));
        self::assertSame('Brand-Logo-2026.png', $browserFileSinksService->sanitizeFileName('Brand Logo 2026.png', 'asset'));
        self::assertSame('asset-1', $browserFileSinksService->sanitizeFileName('..', 'asset-1'));
    }

    public function testWithoutASinkTheFileIsRefused(): void
    {
        $browserFileSinksService = new BrowserFileSinksService();
        $browserFileSinksService->setFileSinks([]);
        $browserFileToStore = new BrowserFileToStore();
        $browserFileToStore->setBytes('{}');
        $browserStoredFile = $browserFileSinksService->storeFile($browserFileToStore, new BrowserFileSinkContext());
        self::assertFalse($browserStoredFile->isStored());
        self::assertSame('no file sink is set up', $browserStoredFile->refusalReason);
    }
}
