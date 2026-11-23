<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserNavigate`: open a page in the session (a new session on the first call). */
class BrowserNavigateRequestDto extends RequestDto
{
    /** @var string The address: one the operator gave, the start page of a host the operator named, or one a result showed in full. Any other address asks the operator first */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $url = '';

    /** @var string|null The tab to load it in (`t1`, …); default the active tab */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $tab = null;

    /** @var bool|null Open it in a new tab */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $newTab = null;
}
