<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `browserExportAssets`: files of the current page into the workspace. */
class BrowserExportAssetsRequestDto extends RequestDto
{
    /** @var string[] `stylesheets`, `logo`, `screenshots`, `fonts` (fonts only when the operator asked; their licence rarely allows reuse) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $kinds = [];
}
