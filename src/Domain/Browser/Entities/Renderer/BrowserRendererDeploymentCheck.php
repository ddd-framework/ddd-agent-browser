<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Entities\Renderer;

use DDD\Domain\Base\Entities\ValueObject;

/** The result of checking one renderer deployment against this package (app:browser:check-renderer) */
class BrowserRendererDeploymentCheck extends ValueObject
{
    /** @var string|null `stateless`, `sessions` or `stateless+sessions` */
    public ?string $deployment = null;

    public ?string $baseUrl = null;

    public ?BrowserRendererHealth $health = null;

    /** @var string[] What does not fit; empty = the deployment serves this package */
    public array $problems = [];

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->deployment);
    }
}
