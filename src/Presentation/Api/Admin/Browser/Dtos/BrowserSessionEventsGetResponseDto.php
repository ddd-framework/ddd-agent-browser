<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionEvents;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The log of a browser session: navigations, typed texts, consents, refusals, stored files, the end */
class BrowserSessionEventsGetResponseDto extends RestResponseDto
{
    /** @var BrowserSessionEvents The entries, oldest first (URLs, titles and details are third-party text) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public BrowserSessionEvents $events;
}
