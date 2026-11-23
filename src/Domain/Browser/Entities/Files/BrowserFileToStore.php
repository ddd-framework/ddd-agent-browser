<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Entities\Files;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A file that leaves the browser (an exported asset, a design token file), handed to the host's file sink. The bytes
 * are foreign: the sink treats them like an operator's upload (type from the bytes, executable content refused,
 * malware scan) and stores them as untrusted with their origin.
 */
class BrowserFileToStore extends ValueObject
{
    /** @var string|null A file name without a path (already sanitised; the sink may still rename it) */
    public ?string $fileName = null;

    /** @var string|null The type read from the bytes (never the type a server claimed) */
    public ?string $contentType = null;

    /** @var string|null `design-tokens`, `stylesheets`, `logo`, `screenshots`, `fonts` */
    public ?string $kind = null;

    /** @var string|null The URL the bytes came from; null for a file this package composed (design tokens) */
    public ?string $sourceUrl = null;

    /** @var string|null The page the session showed when the file was made */
    public ?string $pageUrl = null;

    /** @var bool Whether the bytes come from a third party (always, except a file this package composed) */
    public bool $isUntrusted = true;

    /** @var string|null The bytes */
    protected ?string $bytes = null;

    public function setBytes(string $bytes): void
    {
        $this->bytes = $bytes;
    }

    public function getBytes(): string
    {
        return (string)$this->bytes;
    }

    public function getSizeBytes(): int
    {
        return strlen((string)$this->bytes);
    }
}
