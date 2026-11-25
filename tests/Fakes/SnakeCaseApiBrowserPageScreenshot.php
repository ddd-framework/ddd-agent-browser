<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;

/**
 * What a host's same-path subclass of the screenshot call looks like: another screenshot service with a snake_case API,
 * reached through the Argus gateway as an internal call, answering `{status: "success", content: <base64>}`. Its
 * endpoint stands for the host's own configuration.
 */
class SnakeCaseApiBrowserPageScreenshot extends ArgusBrowserPageScreenshot
{
    /** @var string|null The host's configured endpoint path; null = not configured */
    public static ?string $configuredEndpointPath = null;

    public function isRenderingAvailable(): bool
    {
        return static::$configuredEndpointPath !== null;
    }

    protected function buildRendererEndpoint(): ?string
    {
        return static::$configuredEndpointPath !== null ? 'POST:' . static::$configuredEndpointPath : null;
    }

    protected function isExternalCall(): bool
    {
        return false;
    }

    protected function buildRequestHeaders(): array
    {
        return [];
    }

    public function buildRendererBody(): array
    {
        return [
            'url' => (string)$this->url,
            'response_type' => 'base64',
            'viewport_width' => $this->viewportWidth,
            'viewport_height' => $this->viewportHeight,
            'is_mobile' => $this->isMobile,
            'full_page' => $this->fullPage,
            'image_type' => 'png',
        ];
    }

    protected function mapRendererResponse(mixed $callResponseData, ?ArgusApiOperation $apiOperation): void
    {
        $this->content = null;
        $this->failureReason = null;
        if (is_object($callResponseData) && ($callResponseData->status ?? null) === 'success'
            && is_string($callResponseData->content ?? null) && $callResponseData->content !== ''
        ) {
            $this->content = $callResponseData->content;
            return;
        }
        $errorCode = is_object($callResponseData) && is_string($callResponseData->error ?? null) ? $callResponseData->error : null;
        $this->failureReason = $errorCode !== null && preg_match('/^[a-z_]{2,40}$/', $errorCode) === 1
            ? strtoupper($errorCode)
            : BrowserPageScreenshot::REASON_FETCH_FAILED;
    }
}
