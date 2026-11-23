<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserScroll`. */
class BrowserScrollRequestDto extends RequestDto
{
    /** @var string|null `down` (default), `up`, `top` or `bottom` */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $direction = null;

    /** @var string|null Scroll this element into view instead */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $ref = null;
}
