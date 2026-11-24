<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions\Answers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One file the renderer fetched for an asset export (a guarded fetch, ≤ 25 MB). Its `mimeType` is the server's claim:
 * the type that counts is read from the bytes.
 */
class BrowserAssetFile extends ValueObject
{
    /** @var string|null `stylesheets`, `logo`, `screenshots` or `fonts` */
    public ?string $kind = null;

    public ?string $name = null;

    /** @var string|null The type the source claimed (never trusted) */
    public ?string $mimeType = null;

    public ?string $sourceUrl = null;

    public ?int $size = null;

    /** @var string|null The bytes, base64 */
    public ?string $content = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
