<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserSnapshot`: a view of the current page. */
class BrowserSnapshotRequestDto extends RequestDto
{
    /** @var string|null `outline` (landmarks, headings, counts; default), `interactive` (links, buttons, fields with refs, viewport first) or `text` (a region as markdown) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $mode = null;

    /** @var string|null Only the subtree of this ref (from an earlier snapshot) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $ref = null;

    /** @var string|null Only parts that contain this text */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $find = null;

    /** @var string|null The cursor of the previous page of the same view */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $cursor = null;
}
