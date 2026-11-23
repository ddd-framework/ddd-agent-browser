<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Entities\Renderer;

use DDD\Domain\Base\Entities\ValueObject;

/** The answer of the renderer's `GET /healthz` */
class BrowserRendererHealth extends ValueObject
{
    /** @var string|null `ok` once Chrome runs, `starting` before (HTTP 503) */
    public ?string $status = null;

    /** @var string|null The renderer's contract version (its package version) */
    public ?string $contractVersion = null;

    /** @var string|null The Chrome version the image runs */
    public ?string $chromeVersion = null;

    /** @var string|null `stateless`, `session` or `all`: session routes exist only in `session` and `all` */
    public ?string $role = null;

    /** @var int|null The HTTP status of the answer; null when nothing answered */
    public ?int $httpStatusCode = null;

    /** @var string|null Why the probe failed (transport, unreadable answer) */
    public ?string $failureReason = null;

    public function isReady(): bool
    {
        return $this->status === 'ok' && $this->httpStatusCode === 200;
    }

    public function servesSessions(): bool
    {
        return in_array($this->role, [BrowserRendererContract::ROLE_SESSION, BrowserRendererContract::ROLE_ALL], true);
    }
}
