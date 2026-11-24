<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The log of one browser session */
class BrowserSessionEventsGetRequestDto extends RequestDto
{
    /** @var int The session's id */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $browserSessionId = 0;

    /** @var int|null Entries to answer, oldest first (default 500, at most 2000) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;
}
