<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions\Answers;

use DDD\Domain\Base\Entities\ValueObject;

/** One tab of a session */
class BrowserTab extends ValueObject
{
    /** @var string|null The renderer's tab id (`t1`, `t2`, …) */
    public ?string $tab = null;

    public ?string $url = null;

    public ?string $title = null;

    public bool $active = false;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)($this->tab ?? spl_object_id($this)));
    }
}
