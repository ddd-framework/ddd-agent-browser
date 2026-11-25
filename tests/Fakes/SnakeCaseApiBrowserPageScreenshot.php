<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Fakes;

use DDD\Domain\AI\Entities\Browser\Pages\BrowserPageScreenshot;
use DDD\Domain\AI\Repo\Argus\Browser\Pages\ArgusBrowserPageScreenshot;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;

/**
 * What a host's same-path subclass of the screenshot call looks like: another screenshot service with a snake_case API
 * on a relative endpoint of the application's Argus routing (internal call, no token header), answering
 * `{status: "success", content: "<base64>"}`. Its endpoint stands for the host's own configuration.
 */
class SnakeCaseApiBrowserPageScreenshot extends ArgusBrowserPageScreenshot
{
    /** @var string|null The host's configured endpoint path; null = not configured */
    public static ?string $configuredEndpointPath = null;

    public function isRenderingAvailable(): bool
    {
        return static::$configuredEndpointPath !== null;
    }

    protected function buildLoadEndpoint(?string $baseUrl): ?string
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

    protected function buildRequestBody(): array
    {
        return [
            'url' => (string)$this->url, 'responseType' => 'base64',
            'viewport_width' => $this->viewportWidth, 'viewport_height' => $this->viewportHeight,
            'width' => $this->viewportWidth, 'height' => $this->viewportHeight, 'density' => 1, 'type' => 'png',
            'javascript_enabled' => true, 'redirect_enabled' => true, 'dom_timeout' => $this->domTimeoutSeconds,
            'min_wait' => $this->minWaitSeconds, 'is_mobile' => $this->isMobile, 'force_full_page' => $this->fullPage,
            'emulate_full_page' => true, 'wait_until' => 'networkidle0', 'omit_background' => false,
        ];
    }

    protected function findContentOfAnswer(mixed $callResponseData, ?ArgusApiOperation $apiOperation): ?string
    {
        return ($callResponseData->status ?? null) === 'success' && is_string($callResponseData->content ?? null) ? $callResponseData->content : null;
    }

    public static function findFailureReasonOfAnswer(mixed $callResponseData, ?ArgusApiOperation $apiOperation = null): string
    {
        $errorCode = is_object($callResponseData) && is_string($callResponseData->error ?? null) ? $callResponseData->error : '';
        return match ($errorCode) {
            'bot_challenge' => BrowserPageScreenshot::REASON_BOT_CHALLENGE,
            'quota_exceeded' => BrowserPageScreenshot::REASON_BUSY,
            default => parent::findFailureReasonOfAnswer($callResponseData, $apiOperation),
        };
    }
}
