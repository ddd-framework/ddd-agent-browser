<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserInspect`: computed styles of one element and the CSS rules behind them. */
class BrowserInspectRequestDto extends RequestDto
{
    /** @var string The element (from the newest snapshot) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $ref = '';

    /** @var string[] Only these CSS properties (`color`, `font-family`, …); default the ones that differ from the browser default */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $properties = [];
}
