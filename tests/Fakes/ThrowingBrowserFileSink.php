<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Files\BrowserFileSinkContext;
use DDD\Domain\AI\Entities\Browser\Files\BrowserFileToStore;
use DDD\Domain\AI\Entities\Browser\Files\BrowserStoredFile;
use DDD\Domain\AI\Services\Browser\BrowserFileSink;
use RuntimeException;

/** A host sink that fails while storing */
class ThrowingBrowserFileSink implements BrowserFileSink
{
    public function storeFile(BrowserFileToStore $browserFileToStore, BrowserFileSinkContext $browserFileSinkContext): BrowserStoredFile
    {
        throw new RuntimeException('store unavailable');
    }
}
