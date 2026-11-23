<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserExtractDesign`: the design tokens of the session's current page. */
class BrowserExtractDesignRequestDto extends RequestDto
{
    /** @var string|null `page` (default) or `ref` (only the subtree of `ref`) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $scope = null;

    /** @var string|null The element for `scope: ref` */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $ref = null;
}
