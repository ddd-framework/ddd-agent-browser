<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions\Answers;

use DDD\Domain\Base\Entities\ValueObject;

/** One field of a submission the renderer stopped */
class BrowserSubmissionField extends ValueObject
{
    public ?string $name = null;

    public ?string $value = null;

    /** @var bool Whether the value was typed in this session (else the page filled it) */
    public bool $typed = false;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
