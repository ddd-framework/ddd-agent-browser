<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One browser session, by its id */
class BrowserSessionGetRequestDto extends RequestDto
{
    /** @var int The session's id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $browserSessionId = 0;
}
