<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserSelectOption`. */
class BrowserSelectOptionRequestDto extends RequestDto
{
    /** @var string The ref of the select field */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $ref = '';

    /** @var string[] The options to select: only options the page offers */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $values = [];
}
