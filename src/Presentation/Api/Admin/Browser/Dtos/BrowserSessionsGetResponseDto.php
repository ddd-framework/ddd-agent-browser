<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessions;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A page of the browser sessions and whether sessions are set up */
class BrowserSessionsGetResponseDto extends RestResponseDto
{
    /** @var BrowserSessions The sessions, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public BrowserSessions $sessions;

    /** @var bool A further page exists */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $hasMore = false;

    /** @var bool Interactive sessions are switched on (AI.Browser.sessions.enabled) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $areSessionsEnabled = false;

    /** @var bool A renderer deployment for the session routes is configured (AI.Browser.renderer) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isConfigured = false;
}
