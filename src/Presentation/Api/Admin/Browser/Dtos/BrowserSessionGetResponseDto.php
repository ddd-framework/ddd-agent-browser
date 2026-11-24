<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One browser session */
class BrowserSessionGetResponseDto extends RestResponseDto
{
    /** @var BrowserSession The session */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public BrowserSession $session;
}
