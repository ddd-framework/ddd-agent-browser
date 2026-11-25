<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Renderer;

use DDD\Domain\Base\Entities\ValueObject;

/** The result of checking one renderer deployment against this package (app:agent-browser:check-renderer) */
class BrowserRendererDeploymentCheck extends ValueObject
{
    /** @var string|null `stateless`, `sessions`, `stateless+sessions` or `pageScreenshots` (the application's own screenshot call) */
    public ?string $deployment = null;

    public ?string $baseUrl = null;

    public ?BrowserRendererHealth $health = null;

    /** @var string|null A remark on a row that is no probed deployment (e.g. the application's own screenshot call, not probed) */
    public ?string $note = null;

    /** @var string[] What does not fit; empty = the deployment serves this package */
    public array $problems = [];

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->deployment);
    }
}
