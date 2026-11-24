<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;

/**
 * One call of a session route of the renderer (`POST /browser/session/<action>`): the action and its parameters. Only
 * the parameters that are set go on the wire ({@see toRendererBody()}).
 */
class BrowserSessionRequest extends ValueObject
{
    /** @var string|null One of BrowserRendererContract::SESSION_ACTIONS */
    public ?string $action = null;

    /** @var string|null The renderer's key of the session; null for a navigation that opens one */
    public ?string $sessionKey = null;

    /** @var string|null The tab (`t1`, …); null = the active tab */
    public ?string $tab = null;

    // navigate, tabs open
    public ?string $url = null;
    public ?bool $newTab = null;
    public ?string $cookieBanners = null;

    // snapshot
    public ?string $mode = null;
    public ?string $ref = null;
    public ?string $find = null;
    public ?string $cursor = null;
    public ?int $maxChars = null;

    // act (`click`, `type`, `select`, `press`, `scroll`)
    public ?string $actAction = null;
    public ?string $text = null;
    public ?bool $submit = null;
    /** @var string[]|null Options to select */
    public ?array $values = null;
    public ?string $key = null;
    public ?string $direction = null;
    public ?string $releaseToken = null;

    // screenshot
    public ?string $target = null;
    public ?int $fromY = null;
    public ?int $height = null;
    public ?string $format = null;
    public ?int $quality = null;

    // tabs (`list`, `open`, `select`, `close`)
    public ?string $tabAction = null;

    // analyse (`design`, `inspect`, `assets`)
    public ?string $analysisName = null;
    /** @var string[]|null CSS properties `inspect` reports */
    public ?array $properties = null;
    /** @var string[]|null Asset kinds `assets` fetches */
    public ?array $kinds = null;

    // log
    public ?int $since = null;

    public static function forAction(string $action, ?string $sessionKey = null): static
    {
        $browserSessionRequest = new static();
        $browserSessionRequest->action = $action;
        $browserSessionRequest->sessionKey = $sessionKey;
        return $browserSessionRequest;
    }

    /**
     * The route of the call, relative to the sessions deployment's base URL.
     * @return string
     */
    public function buildRoute(): string
    {
        return BrowserRendererContract::ROUTE_SESSION_PREFIX . $this->action;
    }

    /**
     * The JSON body of the call (the renderer contract's parameter names).
     * @return array<string, mixed>
     */
    public function toRendererBody(): array
    {
        $body = [
            'sessionKey' => $this->sessionKey,
            'tab' => $this->tab,
            'url' => $this->url,
            'newTab' => $this->newTab,
            'cookieBanners' => $this->cookieBanners,
            'mode' => $this->mode,
            'ref' => $this->ref,
            'find' => $this->find,
            'cursor' => $this->cursor,
            'maxChars' => $this->maxChars,
            'text' => $this->text,
            'submit' => $this->submit,
            'values' => $this->values,
            'key' => $this->key,
            'direction' => $this->direction,
            'releaseToken' => $this->releaseToken,
            'target' => $this->target,
            'fromY' => $this->fromY,
            'height' => $this->height,
            'format' => $this->format,
            'quality' => $this->quality,
            'properties' => $this->properties,
            'kinds' => $this->kinds,
            'since' => $this->since,
        ];
        // the contract names the act's and the tabs' verb `action`, the analysis `name`
        if ($this->action === BrowserRendererContract::SESSION_ACTION_ACT) {
            $body['action'] = $this->actAction;
        } elseif ($this->action === BrowserRendererContract::SESSION_ACTION_TABS) {
            $body['action'] = $this->tabAction;
        } elseif ($this->action === BrowserRendererContract::SESSION_ACTION_ANALYSE) {
            $body['name'] = $this->analysisName;
        }
        $bodyWithSetValues = [];
        foreach ($body as $parameterName => $value) {
            if ($value !== null) {
                $bodyWithSetValues[$parameterName] = $value;
            }
        }
        return $bodyWithSetValues;
    }
}
