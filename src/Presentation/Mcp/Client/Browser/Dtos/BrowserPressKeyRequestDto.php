<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserPressKey`. */
class BrowserPressKeyRequestDto extends RequestDto
{
    /** @var string The key (`Enter`, `Escape`, `Tab`, `ArrowDown`, …) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $key = '';

    /** @var string|null The element that has the focus first; default the focused one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $ref = null;
}
