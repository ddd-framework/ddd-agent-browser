<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Domain\AI\Entities\Browser\Agents\BrowserPageScreenshotPart;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Positive;

/**
 * Input for the `getWebPageAsScreenshot` tool: the URL of the page to SEE, which PART of it (a window of two 2000-px
 * segments; the answer names the page height and every other part) and the device. Slice size and scaling are the
 * module's ({@see \DDD\Domain\AI\Services\Browser\Agents\BrowserAgentPagesService::capturePagePartForModel()}); the
 * shared `noCache` asks for a fresh render instead of the cached one.
 */
class BrowserPageScreenshotGetRequestDto extends RequestDto
{
    /** @var string The full URL of the web page to render and view (https://…). */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $url;

    /** @var int|null Which part of the page: 1 = the top 4,000 px (default), 2 = 4,000–8,000 px, … — the answer says how many parts the page has. */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Positive]
    public ?int $part = null;

    /** @var string|null `desktop` (default, 1024 px wide) or `mobile` (390 px wide, phone emulation). */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Choice(choices: BrowserPageScreenshotPart::DEVICES)]
    public ?string $device = null;
}
