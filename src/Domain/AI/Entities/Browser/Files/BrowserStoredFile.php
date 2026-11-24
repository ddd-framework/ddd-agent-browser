<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Files;

use DDD\Domain\Base\Entities\ValueObject;

/** What the host's file sink stored: the path the agent can use, or why it refused */
class BrowserStoredFile extends ValueObject
{
    /** @var string|null The path in the workspace (or the host's own reference); null when refused */
    public ?string $path = null;

    public ?string $contentType = null;

    public ?int $sizeBytes = null;

    /** @var string|null Why the sink refused the file (executable content, malware, type not allowed) */
    public ?string $refusalReason = null;

    public function isStored(): bool
    {
        return $this->path !== null && $this->refusalReason === null;
    }

    public static function refused(string $refusalReason): static
    {
        $browserStoredFile = new static();
        $browserStoredFile->refusalReason = $refusalReason;
        return $browserStoredFile;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
