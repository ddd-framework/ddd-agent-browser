<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserType`. */
class BrowserTypeRequestDto extends RequestDto
{
    /** @var string The ref of the field (from the newest snapshot) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $ref = '';

    /** @var string The text (at most 200 characters): the operator's own words; any other text asks the operator first */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $text = '';

    /** @var bool|null Press Enter after typing (a form that sends data to another site stops for the operator's yes) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $submit = null;
}
