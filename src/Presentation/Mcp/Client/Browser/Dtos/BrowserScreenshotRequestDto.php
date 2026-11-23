<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserScreenshot`: pictures of the current state of the page. */
class BrowserScreenshotRequestDto extends RequestDto
{
    /** @var string|null `viewport` (default), `element` (needs `ref`) or `fullPagePart` (one part of 4,000 px of the whole page, see `part`) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $target = null;

    /** @var string|null The element for `target: element` */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $ref = null;

    /** @var int|null The part of the full page: 1 = the top 4,000 px, 2 = the next, … */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $part = null;
}
