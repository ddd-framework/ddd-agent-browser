<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser\Dtos;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer of the browser tools: compact markdown for the model (page strings inside untrusted marks) */
class BrowserMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string The answer */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $text = '';

    public static function withText(string $text): static
    {
        $browserMcpResponseDto = new static();
        $browserMcpResponseDto->text = $text;
        return $browserMcpResponseDto;
    }

    public function toLlmMarkdown(): string
    {
        return $this->text;
    }
}
