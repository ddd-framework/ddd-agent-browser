<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserClick`. */
class BrowserClickRequestDto extends RequestDto
{
    /** @var string The ref of the link or button (from the newest snapshot) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $ref = '';
}
