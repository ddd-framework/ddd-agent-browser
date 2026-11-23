<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserTabs`. */
class BrowserTabsRequestDto extends RequestDto
{
    /** @var string|null `list` (default), `open` (with `url`), `select` or `close` (with `tab`) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $action = null;

    /** @var string|null The tab to select or close (`t2`, …) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $tab = null;

    /** @var string|null The address a new tab opens (same origin rule as browserNavigate) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $url = null;
}
