<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Browser\BrowserAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolFetchesModelChosenUrl;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRefreshesState;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionAnswer;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionRequest;
use DDD\Domain\AI\Services\Browser\Agents\BrowserAgentAnswersService;
use DDD\Domain\AI\Services\Browser\Agents\BrowserAgentSessionsService;
use DDD\Domain\AI\Services\Browser\BrowserAccessService;
use DDD\Domain\AI\Services\Browser\BrowserRendererService;
use DDD\Domain\AI\Services\Browser\BrowserSessionsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserClickRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserCloseRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserMcpResponseDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserNavigateRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserPressKeyRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserScreenshotRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserScrollRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserSelectOptionRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserSnapshotRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserTabsRequestDto;
use DDD\Presentation\Mcp\Client\Browser\Dtos\BrowserTypeRequestDto;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;

/**
 * Interactive browsing for staff agents: one fresh browser context per conversation on the renderer's sessions
 * deployment (no profile, no logins), driven by an accessibility snapshot with refs. Every tool is a READ for the
 * platform and returns untrusted content. A model-written URL or typed text runs only with a known origin (else the
 * operator's consent), and a submission the renderer stopped runs only after the operator's yes, once.
 */
#[MCPRequiresAccountRights(BrowserAccountRights::USE_BROWSER)]
class BrowserSessionMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.web.browser';

    public static ?string $description = 'Drive a real browser for staff: browserNavigate, browserSnapshot (outline, interactive refs, text), browserClick, browserType, browserSelectOption, browserPressKey, browserScroll, browserScreenshot, browserTabs, browserClose.';

    public const array SNAPSHOT_MODES = ['outline', 'interactive', 'text'];
    public const array SCROLL_DIRECTIONS = ['down', 'up', 'top', 'bottom'];
    public const array SCREENSHOT_TARGETS = ['viewport', 'element', 'fullPagePart'];
    public const array TAB_ACTIONS = ['list', 'open', 'select', 'close'];

    public static function isAvailable(): bool
    {
        /** @var BrowserAccessService $browserAccessService */
        $browserAccessService = DDDService::instance()->getService(BrowserAccessService::class);
        /** @var BrowserRendererService $browserRendererService */
        $browserRendererService = DDDService::instance()->getService(BrowserRendererService::class);
        return $browserAccessService->areSessionsEnabled() && $browserRendererService->isSessionEndpointConfigured();
    }

    /**
     * Open a page in this conversation's browser session (the first call opens the session). Consent banners are
     * rejected, never accepted. Answers the final URL, status, redirects, the banner outcome and an outline of the
     * page. The address must come from the operator (or be the start page of a host the operator named) or from a
     * result shown in full; any other address asks the operator first.
     *
     * @example {"url": "https://www.example.com/"}
     * @example {"url": "https://www.example.com/contact", "newTab": true}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the text of a third-party web page')]
    #[MCPToolFetchesModelChosenUrl('url')]
    #[MCPToolRefreshesState('a navigation shows the page as it is now')]
    #[MCPToolExecutionTimeout(140, 'a navigation and one more in a fresh session after SESSION_GONE; PHP waits up to 65 s per renderer call')]
    #[MCPToolSummary('Open a URL in the conversation\'s browser session (first call opens it); answers final URL, status, redirects, cookie-banner outcome and an outline. URL must come from the operator or a shown result, else the operator is asked.')]
    public function browserNavigate(
        BrowserNavigateRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        $url = trim($requestDto->url);
        if (!preg_match('~^https?://~i', $url)) {
            throw new BadRequestException('browserNavigate needs a full http(s) address in `url`.');
        }
        $ownerConsentReservation = $browserAgentSessionsService->requireKnownOriginOfUrl($callProvenance, $url, __FUNCTION__);
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, true);
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_NAVIGATE, $browserSession->sessionKey);
        $browserSessionRequest->url = $url;
        $browserSessionRequest->tab = $requestDto->tab;
        $browserSessionRequest->newTab = $requestDto->newTab;
        $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        if ($browserSessionAnswer->reason === BrowserRendererContract::REASON_SESSION_GONE && $browserSession->sessionKey !== null) {
            // the old context is gone: one fresh session for the same navigation
            $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, true);
            $browserSessionRequest->sessionKey = null;
            $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        }
        $browserAgentSessionsService->markConsentUsed($callProvenance, $ownerConsentReservation);
        $this->throwIfRefused($browserSessionAnswer, $browserAgentAnswersService);
        return BrowserMcpResponseDto::withText($browserAgentAnswersService->buildNavigationText($browserSessionAnswer));
    }

    /**
     * A view of the current page, paged: `outline` (landmarks, headings, counts per region), `interactive` (links,
     * buttons and fields with refs, viewport first) or `text` (a region as markdown). Narrow it with `ref` (a subtree)
     * or `find` (a text filter); `cursor` gives the next page. Refs are valid until the next snapshot of the tab.
     *
     * @example {"mode": "interactive"}
     * @example {"mode": "text", "ref": "e42"}
     * @example {"mode": "interactive", "find": "Impressum"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the text of a third-party web page')]
    #[MCPToolRefreshesState('a snapshot shows the page as it is now')]
    #[MCPToolExecutionTimeout(80, 'one snapshot in the renderer; PHP waits up to 65 s for the renderer')]
    #[MCPToolSummary('A paged view of the session\'s page: outline | interactive (refs for links, buttons, fields) | text; narrow with ref or find, continue with cursor.')]
    public function browserSnapshot(
        BrowserSnapshotRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        BrowserAccessService $browserAccessService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        $mode = $requestDto->mode ?? 'outline';
        if (!in_array($mode, static::SNAPSHOT_MODES, true)) {
            throw new BadRequestException('`mode` is one of: ' . implode(', ', static::SNAPSHOT_MODES) . '.');
        }
        static::throwUnlessValidRef($requestDto->ref, __FUNCTION__, false);
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_SNAPSHOT, $browserSession->sessionKey);
        $browserSessionRequest->mode = $mode;
        $browserSessionRequest->ref = $requestDto->ref;
        $browserSessionRequest->find = $requestDto->find;
        $browserSessionRequest->cursor = $requestDto->cursor;
        $browserSessionRequest->maxChars = $browserAccessService->getMaxAnswerCharacters();
        $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        $this->throwIfRefused($browserSessionAnswer, $browserAgentAnswersService);
        return BrowserMcpResponseDto::withText($browserAgentAnswersService->buildSnapshotText($browserSessionAnswer));
    }

    /**
     * Click a link or button by its ref from the newest snapshot. Answers what changed: a new URL or dialog and the
     * changed region. A click that would send a form to another site, or post a form, stops and asks the operator.
     *
     * @example {"ref": "e12"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the text of a third-party web page')]
    #[MCPToolRefreshesState('a click changes the page')]
    #[MCPToolExecutionTimeout(140, 'an action the renderer stops as a submission and its released repeat; PHP waits up to 65 s per renderer call')]
    #[MCPToolSummary('Click a ref (link/button) of the newest snapshot; answers what changed. A form submission stops for the operator\'s yes.')]
    public function browserClick(
        BrowserClickRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserSessionRequest = $this->buildActRequest('click', $requestDto->ref);
        return $this->runAct($browserSessionRequest, 'Clicked ' . $requestDto->ref, $browserAgentSessionsService, $browserAgentAnswersService, $callProvenance, __FUNCTION__);
    }

    /**
     * Type a text into a field by its ref (at most 200 characters). The text must be the operator's own words; any
     * other text asks the operator first. Never a password, a payment detail or anything from another tool's result:
     * such fields are refused. `submit: true` presses Enter afterwards; a form that sends data stops for the operator.
     *
     * @example {"ref": "e7", "text": "Kontakt"}
     * @example {"ref": "e7", "text": "Kontakt", "submit": true}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the text of a third-party web page')]
    #[MCPToolRefreshesState('typing changes the page')]
    #[MCPToolExecutionTimeout(140, 'an action the renderer stops as a submission and its released repeat; PHP waits up to 65 s per renderer call')]
    #[MCPToolSummary('Type the operator\'s text (≤ 200 chars) into a field ref; other text asks the operator. submit presses Enter; a sending form stops for the operator\'s yes.')]
    public function browserType(
        BrowserTypeRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        BrowserAccessService $browserAccessService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        $maxTypedTextCharacters = $browserAccessService->getMaxTypedTextCharacters();
        if (mb_strlen($requestDto->text) > $maxTypedTextCharacters) {
            throw new BadRequestException('A typed text has at most ' . $maxTypedTextCharacters . ' characters: nothing was typed.');
        }
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        $ownerConsentReservation = $browserAgentSessionsService->requireKnownOriginOfText($callProvenance, $requestDto->text, $browserSession->currentUrl, __FUNCTION__);
        $browserSessionRequest = $this->buildActRequest('type', $requestDto->ref);
        $browserSessionRequest->text = $requestDto->text;
        $browserSessionRequest->submit = $requestDto->submit;
        $response = $this->runActInSession($browserSession, $browserSessionRequest, 'Typed into ' . $requestDto->ref, $browserAgentSessionsService, $browserAgentAnswersService, $callProvenance, __FUNCTION__);
        $browserAgentSessionsService->markConsentUsed($callProvenance, $ownerConsentReservation);
        return $response;
    }

    /**
     * Select options of a select field by its ref: only options the page offers.
     *
     * @example {"ref": "e19", "values": ["Deutschland"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the text of a third-party web page')]
    #[MCPToolRefreshesState('a selection changes the page')]
    #[MCPToolExecutionTimeout(140, 'an action the renderer stops as a submission and its released repeat; PHP waits up to 65 s per renderer call')]
    #[MCPToolSummary('Select options (only ones the page offers) of a select-field ref; answers what changed.')]
    public function browserSelectOption(
        BrowserSelectOptionRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $values = array_values(array_filter(array_map('strval', $requestDto->values), 'strlen'));
        if ($values === []) {
            throw new BadRequestException('browserSelectOption needs at least one of the offered options in `values`.');
        }
        $browserSessionRequest = $this->buildActRequest('select', $requestDto->ref);
        $browserSessionRequest->values = $values;
        return $this->runAct($browserSessionRequest, 'Selected ' . implode(', ', $values) . ' in ' . $requestDto->ref, $browserAgentSessionsService, $browserAgentAnswersService, $callProvenance, __FUNCTION__);
    }

    /**
     * Press one key (`Enter`, `Escape`, `Tab`, arrows) on the focused element or on `ref`.
     *
     * @example {"key": "Escape"}
     * @example {"key": "Enter", "ref": "e7"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the text of a third-party web page')]
    #[MCPToolRefreshesState('a key press changes the page')]
    #[MCPToolExecutionTimeout(140, 'an action the renderer stops as a submission and its released repeat; PHP waits up to 65 s per renderer call')]
    #[MCPToolSummary('Press a key (Enter, Escape, Tab, arrows) on the focused element or a ref; answers what changed.')]
    public function browserPressKey(
        BrowserPressKeyRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        if (trim($requestDto->key) === '' || mb_strlen($requestDto->key) > 32) {
            throw new BadRequestException('browserPressKey needs one key name in `key` (Enter, Escape, Tab, ArrowDown, …).');
        }
        $browserSessionRequest = $this->buildActRequest('press', $requestDto->ref);
        $browserSessionRequest->key = trim($requestDto->key);
        return $this->runAct($browserSessionRequest, 'Pressed ' . trim($requestDto->key), $browserAgentSessionsService, $browserAgentAnswersService, $callProvenance, __FUNCTION__);
    }

    /**
     * Scroll the page (`down`, `up`, `top`, `bottom`) or bring a ref into view. Answers the interactive elements now
     * in view.
     *
     * @example {"direction": "down"}
     * @example {"ref": "e88"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the text of a third-party web page')]
    #[MCPToolRefreshesState('scrolling changes the view')]
    #[MCPToolExecutionTimeout(80, 'one action in the renderer; PHP waits up to 65 s for the renderer')]
    #[MCPToolSummary('Scroll (down|up|top|bottom) or bring a ref into view; answers the interactive elements now in view.')]
    public function browserScroll(
        BrowserScrollRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $direction = $requestDto->ref === null ? ($requestDto->direction ?? 'down') : null;
        if ($direction !== null && !in_array($direction, static::SCROLL_DIRECTIONS, true)) {
            throw new BadRequestException('`direction` is one of: ' . implode(', ', static::SCROLL_DIRECTIONS) . '.');
        }
        $browserSessionRequest = $this->buildActRequest('scroll', $requestDto->ref);
        $browserSessionRequest->direction = $direction;
        return $this->runAct($browserSessionRequest, $direction !== null ? 'Scrolled ' . $direction : 'Scrolled to ' . $requestDto->ref, $browserAgentSessionsService, $browserAgentAnswersService, $callProvenance, __FUNCTION__);
    }

    /**
     * Pictures of the session's current state (after clicks, a closed banner, scrolling): the viewport, one element by
     * ref, or one part of the full page (`fullPagePart`, part 1 = the top 4,000 px as two pictures). Describe what you
     * see before you open the next part. Needs a vision-capable model.
     *
     * @example {}
     * @example {"target": "element", "ref": "e3"}
     * @example {"target": "fullPagePart", "part": 2}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('the pixels of a third-party web page')]
    #[MCPToolRefreshesState('a screenshot shows the page as it is now')]
    #[MCPToolExecutionTimeout(80, 'one capture in the renderer; PHP waits up to 65 s for the renderer')]
    #[MCPToolSummary('Pictures of the session\'s current page: viewport (default), element by ref, or fullPagePart (part 1 = top 4,000 px as two pictures).')]
    public function browserScreenshot(
        BrowserScreenshotRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): MCPToolResponse {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        $target = $requestDto->target ?? 'viewport';
        if (!in_array($target, static::SCREENSHOT_TARGETS, true)) {
            throw new BadRequestException('`target` is one of: ' . implode(', ', static::SCREENSHOT_TARGETS) . '.');
        }
        if ($target === 'element') {
            static::throwUnlessValidRef($requestDto->ref, __FUNCTION__, true);
        }
        $part = max(1, (int)($requestDto->part ?? 1));
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_SCREENSHOT, $browserSession->sessionKey);
        $browserSessionRequest->target = $target === 'fullPagePart' ? 'fullPage' : $target;
        $browserSessionRequest->ref = $target === 'element' ? $requestDto->ref : null;
        $browserSessionRequest->format = 'png';
        if ($target === 'fullPagePart') {
            $browserSessionRequest->fromY = ($part - 1) * BrowserAgentAnswersService::SCREENSHOT_PART_HEIGHT;
            $browserSessionRequest->height = BrowserAgentAnswersService::SCREENSHOT_PART_HEIGHT;
        }
        $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        $refusalText = $browserAgentAnswersService->buildRefusalText($browserSessionAnswer);
        if ($refusalText !== null) {
            return MCPToolResponse::error($refusalText);
        }
        $pictures = $browserAgentAnswersService->buildScreenshotPictures($browserSessionAnswer);
        if ($pictures === []) {
            return MCPToolResponse::error($target === 'fullPagePart'
                ? 'Part ' . $part . ' of the page has no pixels (the page is shorter, or the picture was no PNG/JPEG). Try part 1.'
                : 'The renderer returned no usable picture (only PNG and JPEG are shown).');
        }
        $mcpToolResponse = new MCPToolResponse();
        $mcpToolResponse->addTextContent(sprintf(
            '%s of %s · %d×%d px%s',
            $target === 'fullPagePart' ? 'Part ' . $part . ' (from ' . number_format(($part - 1) * BrowserAgentAnswersService::SCREENSHOT_PART_HEIGHT) . ' px)' : ucfirst($target),
            $browserSession->currentUrl ?? 'the page',
            (int)$browserSessionAnswer->imageWidth,
            (int)$browserSessionAnswer->imageHeight,
            $browserSessionAnswer->imageTruncated ? ' · cut at the bottom (open the next part)' : ''
        ));
        foreach ($pictures as $picture) {
            $mcpToolResponse->addImageContent($picture, 'image/png');
        }
        return $mcpToolResponse;
    }

    /**
     * The session's tabs (at most 3): `list` them, `open` one with a URL (same origin rule as browserNavigate),
     * `select` or `close` one by its id.
     *
     * @example {}
     * @example {"action": "select", "tab": "t2"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsUntrustedContent('titles and addresses of third-party web pages')]
    #[MCPToolFetchesModelChosenUrl('url')]
    #[MCPToolRefreshesState('tabs change')]
    #[MCPToolExecutionTimeout(80, 'one tab action in the renderer; PHP waits up to 65 s for the renderer')]
    #[MCPToolSummary('The session\'s tabs: list (default), open with url, select or close by tab id.')]
    public function browserTabs(
        BrowserTabsRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        $tabAction = $requestDto->action ?? 'list';
        if (!in_array($tabAction, static::TAB_ACTIONS, true)) {
            throw new BadRequestException('`action` is one of: ' . implode(', ', static::TAB_ACTIONS) . '.');
        }
        $ownerConsentReservation = null;
        if ($tabAction === 'open') {
            $url = trim((string)$requestDto->url);
            if (!preg_match('~^https?://~i', $url)) {
                throw new BadRequestException('`action: open` needs a full http(s) address in `url`.');
            }
            $ownerConsentReservation = $browserAgentSessionsService->requireKnownOriginOfUrl($callProvenance, $url, __FUNCTION__);
        } elseif (in_array($tabAction, ['select', 'close'], true) && ($requestDto->tab === null || $requestDto->tab === '')) {
            throw new BadRequestException('`action: ' . $tabAction . '` needs the `tab` id (from action list).');
        }
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_TABS, $browserSession->sessionKey);
        $browserSessionRequest->tabAction = $tabAction;
        $browserSessionRequest->tab = $requestDto->tab;
        $browserSessionRequest->url = $tabAction === 'open' ? trim((string)$requestDto->url) : null;
        $browserSessionAnswer = $browserAgentSessionsService->runAction($browserSession, $browserSessionRequest, __FUNCTION__);
        $browserAgentSessionsService->markConsentUsed($callProvenance, $ownerConsentReservation);
        $this->throwIfRefused($browserSessionAnswer, $browserAgentAnswersService);
        return BrowserMcpResponseDto::withText($browserAgentAnswersService->buildTabsText($browserSessionAnswer));
    }

    /**
     * Close this conversation's browser session when the task is done (it also ends by itself after 5 idle minutes).
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolExecutionTimeout(80, 'one close in the renderer; PHP waits up to 65 s for the renderer')]
    #[MCPToolSummary('Close the conversation\'s browser session.')]
    public function browserClose(
        BrowserCloseRequestDto $requestDto,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserSessionsService $browserSessionsService,
        AgentToolCallProvenance $callProvenance
    ): BrowserMcpResponseDto {
        $conversationId = $callProvenance->getTreeRootConversationIdForToolCall();
        $browserSession = $conversationId !== null ? $browserSessionsService->findOpenSessionOfConversation($conversationId) : null;
        if (!$browserSession instanceof BrowserSession) {
            return BrowserMcpResponseDto::withText('No browser session is open.');
        }
        if ($browserSession->sessionKey !== null) {
            $browserAgentSessionsService->runAction(
                $browserSession,
                BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_CLOSE, $browserSession->sessionKey),
                __FUNCTION__
            );
        }
        if ($browserSessionsService->findOpenSessionOfConversation((int)$conversationId) !== null) {
            $browserSessionsService->markSessionClosed($browserSession, __FUNCTION__);
        }
        return BrowserMcpResponseDto::withText('The browser session is closed.');
    }

    protected function buildActRequest(string $actAction, ?string $ref): BrowserSessionRequest
    {
        $browserSessionRequest = BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_ACT);
        $browserSessionRequest->actAction = $actAction;
        $browserSessionRequest->ref = $ref !== null && trim($ref) !== '' ? trim($ref) : null;
        return $browserSessionRequest;
    }

    protected function runAct(
        BrowserSessionRequest $browserSessionRequest,
        string $actDescription,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance,
        string $toolName
    ): BrowserMcpResponseDto {
        $browserAgentSessionsService->requireInteractiveBrowsing();
        $browserSession = $browserAgentSessionsService->findSessionOfCall($callProvenance, false);
        return $this->runActInSession($browserSession, $browserSessionRequest, $actDescription, $browserAgentSessionsService, $browserAgentAnswersService, $callProvenance, $toolName);
    }

    protected function runActInSession(
        BrowserSession $browserSession,
        BrowserSessionRequest $browserSessionRequest,
        string $actDescription,
        BrowserAgentSessionsService $browserAgentSessionsService,
        BrowserAgentAnswersService $browserAgentAnswersService,
        AgentToolCallProvenance $callProvenance,
        string $toolName
    ): BrowserMcpResponseDto {
        static::throwUnlessValidRef($browserSessionRequest->ref, $toolName, in_array($browserSessionRequest->actAction, ['click', 'type', 'select'], true));
        $browserSessionRequest->sessionKey = $browserSession->sessionKey;
        $browserSessionAnswer = $browserAgentSessionsService->runActWithSubmissionRule($callProvenance, $browserSession, $browserSessionRequest, $toolName);
        $this->throwIfRefused($browserSessionAnswer, $browserAgentAnswersService);
        return BrowserMcpResponseDto::withText($browserAgentAnswersService->buildActText($browserSessionAnswer, $actDescription));
    }

    /**
     * A ref has the renderer's shape (`e12`, or `f3e12` inside a frame); a required one must be present.
     * @throws BadRequestException
     */
    public static function throwUnlessValidRef(?string $ref, string $toolName, bool $isRequired): void
    {
        if ($ref === null || trim($ref) === '') {
            if ($isRequired) {
                throw new BadRequestException($toolName . ' needs a `ref` from the newest snapshot (browserSnapshot mode interactive), like `e12` or `f3e12`.');
            }
            return;
        }
        if (!BrowserRendererContract::isValidRef(trim($ref))) {
            throw new BadRequestException('`' . $ref . '` is no ref: refs look like `e12` (or `f3e12` inside a frame) and come from the newest snapshot.');
        }
    }

    /**
     * @throws BadRequestException with the refusal sentence when the answer cannot be shown
     */
    protected function throwIfRefused(BrowserSessionAnswer $browserSessionAnswer, BrowserAgentAnswersService $browserAgentAnswersService): void
    {
        $refusalText = $browserAgentAnswersService->buildRefusalText($browserSessionAnswer);
        if ($refusalText !== null) {
            throw new BadRequestException($refusalText);
        }
    }
}
