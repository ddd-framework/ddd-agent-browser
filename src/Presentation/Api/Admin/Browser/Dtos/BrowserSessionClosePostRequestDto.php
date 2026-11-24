<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/** Closing an open browser session by an operator */
class BrowserSessionClosePostRequestDto extends RequestDto
{
    /** @var int The session's id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $browserSessionId = 0;

    /** @var string|null Why the session is closed (written into the session's log) */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 500)]
    public ?string $reason = null;
}
