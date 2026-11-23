<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Exceptions\Conversations\OwnerConsentMissingException;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\Browser\Entities\Files\BrowserFileSinkContext;
use DDD\Domain\Browser\Entities\Origins\BrowserOriginTexts;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\Answers\BrowserSubmission;
use DDD\Domain\Browser\Entities\Sessions\BrowserSession;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionEvent;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionRequest;
use DDD\Domain\Browser\Services\BrowserAccessService;
use DDD\Domain\Browser\Services\BrowserOriginRulesService;
use DDD\Domain\Browser\Services\BrowserRendererService;
use DDD\Domain\Browser\Services\BrowserSessionsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The agent side of the browser sessions: who may browse, the session of the call's conversation, a session action
 * with its log, the origin rules for a model-written URL or text (operator consent otherwise), and the operator's
 * release of a submission the renderer stopped.
 */
class BrowserAgentSessionsService extends Service
{
    /**
     * Refuses a call that may not browse interactively: sessions off, or an account that is not staff.
     * @throws ForbiddenException
     */
    public function requireInteractiveBrowsing(): void
    {
        $browserAccessService = $this->getBrowserAccessService();
        if (!$browserAccessService->areSessionsEnabled()) {
            throw new ForbiddenException('Interactive browsing is switched off on this platform (Browser.sessions.enabled).');
        }
        if (!$browserAccessService->isInteractiveBrowsingAllowed(AuthService::instance()->getAccount())) {
            throw new ForbiddenException('Interactive browsing is for staff only: nothing was opened. Use the page tools (markdown, screenshot) instead.');
        }
    }

    /**
     * The session of the call's conversation (the root of its delegation tree): the open one, or a new one.
     * @param AgentToolCallProvenance|null $callProvenance
     * @param bool $startWhenNone false: refuse when the conversation has no open session
     * @return BrowserSession
     * @throws BadRequestException
     */
    public function findSessionOfCall(?AgentToolCallProvenance $callProvenance, bool $startWhenNone): BrowserSession
    {
        $conversationId = $callProvenance?->getTreeRootConversationIdForToolCall();
        if ($conversationId === null) {
            throw new BadRequestException('A browser session belongs to an agent conversation; this call has none.');
        }
        $browserSessionsService = $this->getBrowserSessionsService();
        if (!$startWhenNone) {
            $browserSession = $browserSessionsService->findOpenSessionOfConversation($conversationId);
            if ($browserSession === null || $browserSession->sessionKey === null) {
                throw new BadRequestException('No browser session is open in this conversation: open a page with browserNavigate first.');
            }
            return $browserSession;
        }
        return $browserSessionsService->findOrStartSessionOfConversation(
            $conversationId,
            $callProvenance?->agentSpaceId,
            $callProvenance?->getConversationOwnerAccountIdOrAuthAccountId(AuthService::instance()->getAccount()?->id)
        );
    }

    /**
     * Runs a session action for the session, applies the answer to it and logs it.
     * @param BrowserSession $browserSession
     * @param BrowserSessionRequest $browserSessionRequest
     * @param string $toolName
     * @return BrowserSessionAnswer
     */
    public function runAction(BrowserSession $browserSession, BrowserSessionRequest $browserSessionRequest, string $toolName): BrowserSessionAnswer
    {
        if ($browserSessionRequest->sessionKey === null && $browserSessionRequest->action !== BrowserRendererContract::SESSION_ACTION_NAVIGATE) {
            $browserSessionRequest->sessionKey = $browserSession->sessionKey;
        }
        $browserSessionAnswer = $this->getBrowserRendererService()->runSessionAction($browserSessionRequest);
        $browserSessionsService = $this->getBrowserSessionsService();
        $browserSessionsService->applyAnswerToSession($browserSession, $browserSessionAnswer);
        $this->recordActionEvent($browserSession, $browserSessionRequest, $browserSessionAnswer, $toolName);
        return $browserSessionAnswer;
    }

    /**
     * The renderer's act and its submission rule: a submission the renderer stopped runs only after the operator's yes,
     * then exactly once with the renderer's release token.
     * @param AgentToolCallProvenance|null $callProvenance
     * @param BrowserSession $browserSession
     * @param BrowserSessionRequest $browserSessionRequest
     * @param string $toolName
     * @return BrowserSessionAnswer
     * @throws OwnerConsentMissingException
     */
    public function runActWithSubmissionRule(
        ?AgentToolCallProvenance $callProvenance,
        BrowserSession $browserSession,
        BrowserSessionRequest $browserSessionRequest,
        string $toolName
    ): BrowserSessionAnswer {
        $browserSessionAnswer = $this->runAction($browserSession, $browserSessionRequest, $toolName);
        if ($browserSessionAnswer->reason !== BrowserRendererContract::REASON_CONSENT_REQUIRED) {
            return $browserSessionAnswer;
        }
        $browserSubmission = null;
        foreach ($browserSessionAnswer->submissions?->getElements() ?? [] as $candidateSubmission) {
            if ($candidateSubmission->isReleasable()) {
                $browserSubmission = $candidateSubmission;
                break;
            }
        }
        if (!$browserSubmission instanceof BrowserSubmission) {
            // only dropped requests: no release exists, nothing to ask (the answer text says so)
            $this->getBrowserSessionsService()->recordEvent($browserSession, BrowserSessionEvent::EVENT_REFUSED, $toolName, $browserSessionAnswer->finalUrl, 'typed text to another site dropped by the renderer');
            return $browserSessionAnswer;
        }
        $ownerConsentsService = $this->getOwnerConsentsService();
        $ownerConsentReservation = $ownerConsentsService->reserveOwnerConsentForStep($callProvenance, OwnerConsentRequest::forAction(
            $toolName,
            'send data from a web form',
            $browserSubmission->buildDescription(),
            'the page receives these values; this cannot be taken back',
            $toolName . '|' . $browserSubmission->buildConsentKey()
        ));
        if (!$ownerConsentReservation->isWriteAllowed()) {
            $this->getBrowserSessionsService()->recordEvent($browserSession, BrowserSessionEvent::EVENT_CONSENT_REQUIRED, $toolName, $browserSubmission->target, $browserSubmission->buildDescription());
            throw new OwnerConsentMissingException(
                'The page wants to send data: ' . $browserSubmission->buildDescription() . '. Nothing was sent. '
                . $ownerConsentReservation->buildModelFacingText()
                . ' After the operator\'s yes, repeat exactly this action.'
            );
        }
        $releasingRequest = clone $browserSessionRequest;
        $releasingRequest->releaseToken = $browserSubmission->releaseToken;
        $releasedAnswer = $this->runAction($browserSession, $releasingRequest, $toolName);
        $ownerConsentsService->markConsentUsed($callProvenance, $ownerConsentReservation);
        $this->getBrowserSessionsService()->recordEvent($browserSession, BrowserSessionEvent::EVENT_SUBMISSION_RELEASED, $toolName, $browserSubmission->target, $browserSubmission->buildDescription());
        return $releasedAnswer;
    }

    /**
     * The origin rule for a URL the model wants to open: known (operator message, a named host's start page, a result)
     * runs; anything else needs the operator's consent.
     * @param AgentToolCallProvenance|null $callProvenance
     * @param string $url
     * @param string $toolName
     * @return OwnerConsentReservation|null the reservation to mark used after the navigation; null when no consent was needed
     * @throws OwnerConsentMissingException
     */
    public function requireKnownOriginOfUrl(?AgentToolCallProvenance $callProvenance, string $url, string $toolName): ?OwnerConsentReservation
    {
        if ($this->getBrowserOriginRulesService()->isUrlOfKnownOrigin($url, $this->buildOriginTexts($callProvenance))) {
            return null;
        }
        $host = $this->getBrowserOriginRulesService()->extractHost($url) ?? $url;
        return $this->getOwnerConsentsService()->requireOwnerConsentForStep($callProvenance, OwnerConsentRequest::forAction(
            $toolName,
            'open an address the operator did not give',
            $url,
            'the address is sent to ' . $host . '; it may carry facts from this conversation',
            $toolName . '|' . $url
        ));
    }

    /**
     * The origin rule for a text the model wants to type: the operator's own words run; anything else needs consent.
     * @param AgentToolCallProvenance|null $callProvenance
     * @param string $text
     * @param string|null $pageUrl
     * @param string $toolName
     * @return OwnerConsentReservation|null
     * @throws OwnerConsentMissingException
     */
    public function requireKnownOriginOfText(?AgentToolCallProvenance $callProvenance, string $text, ?string $pageUrl, string $toolName): ?OwnerConsentReservation
    {
        if ($this->getBrowserOriginRulesService()->isTextFromOperator($text, $this->buildOriginTexts($callProvenance))) {
            return null;
        }
        $host = $pageUrl !== null ? ($this->getBrowserOriginRulesService()->extractHost($pageUrl) ?? $pageUrl) : 'the page';
        return $this->getOwnerConsentsService()->requireOwnerConsentForStep($callProvenance, OwnerConsentRequest::forAction(
            $toolName,
            'type a text the operator did not give',
            '"' . $text . '" on ' . $host,
            'the text goes to ' . $host,
            $toolName . '|' . $host . '|' . hash('sha256', $text)
        ));
    }

    public function markConsentUsed(?AgentToolCallProvenance $callProvenance, ?OwnerConsentReservation $ownerConsentReservation): void
    {
        if ($ownerConsentReservation !== null) {
            $this->getOwnerConsentsService()->markConsentUsed($callProvenance, $ownerConsentReservation);
        }
    }

    /**
     * What the operator wrote and what the conversation's tool results showed.
     * @param AgentToolCallProvenance|null $callProvenance
     * @return BrowserOriginTexts
     */
    public function buildOriginTexts(?AgentToolCallProvenance $callProvenance): BrowserOriginTexts
    {
        $conversationMessages = $this->findLoadedMessagesOfCall($callProvenance);
        $operatorTexts = [];
        $resultTexts = [];
        foreach ($conversationMessages?->getElements() ?? [] as $conversationMessage) {
            if ($conversationMessage->isOwnerText()) {
                $operatorTexts[] = (string)($conversationMessage->message ?? '');
            }
            if (isset($conversationMessage->toolCall)) {
                $resultTexts[] = (string)($conversationMessage->toolCall->result ?? '');
            }
        }
        return BrowserOriginTexts::fromTexts($operatorTexts, $resultTexts);
    }

    /**
     * Whose files a tool's file belongs to.
     * @param AgentToolCallProvenance|null $callProvenance
     * @param string $toolName
     * @return BrowserFileSinkContext
     */
    public function buildFileSinkContext(?AgentToolCallProvenance $callProvenance, string $toolName): BrowserFileSinkContext
    {
        $browserFileSinkContext = new BrowserFileSinkContext();
        $browserFileSinkContext->conversationId = $callProvenance?->getTreeRootConversationIdForToolCall();
        $browserFileSinkContext->agentSpaceId = $callProvenance?->agentSpaceId;
        $browserFileSinkContext->accountId = $callProvenance?->getConversationOwnerAccountIdOrAuthAccountId(AuthService::instance()->getAccount()?->id);
        $browserFileSinkContext->workspaceKey = $callProvenance?->workspaceKey;
        $browserFileSinkContext->toolName = $toolName;
        return $browserFileSinkContext;
    }

    protected function recordActionEvent(BrowserSession $browserSession, BrowserSessionRequest $browserSessionRequest, BrowserSessionAnswer $browserSessionAnswer, string $toolName): void
    {
        $browserSessionsService = $this->getBrowserSessionsService();
        if ($browserSessionAnswer->isRendererFault()) {
            $browserSessionsService->recordEvent($browserSession, BrowserSessionEvent::EVENT_REFUSED, $toolName, $browserSessionRequest->url, 'renderer: ' . ($browserSessionAnswer->httpStatusCode ?? 'no answer') . ' ' . $browserSessionAnswer->error);
            return;
        }
        if ($browserSessionAnswer->reason === BrowserRendererContract::REASON_CONSENT_REQUIRED) {
            // the submission rule logs the question or the release itself
            return;
        }
        $refusalReasons = [
            BrowserRendererContract::REASON_BLOCKED, BrowserRendererContract::REASON_BOT_CHALLENGE, BrowserRendererContract::REASON_FIELD_REFUSED,
            BrowserRendererContract::REASON_STALE_REF, BrowserRendererContract::REASON_TIMEOUT,
        ];
        if (in_array($browserSessionAnswer->reason, $refusalReasons, true)) {
            $browserSessionsService->recordEvent($browserSession, BrowserSessionEvent::EVENT_REFUSED, $toolName, $browserSessionRequest->url ?? $browserSessionAnswer->finalUrl, (string)$browserSessionAnswer->reason);
            return;
        }
        $eventType = match (true) {
            $browserSessionRequest->action === BrowserRendererContract::SESSION_ACTION_NAVIGATE => BrowserSessionEvent::EVENT_NAVIGATED,
            $browserSessionRequest->action === BrowserRendererContract::SESSION_ACTION_TABS => BrowserSessionEvent::EVENT_TAB_CHANGED,
            $browserSessionRequest->actAction === 'type' => BrowserSessionEvent::EVENT_TYPED,
            $browserSessionRequest->actAction === 'select' => BrowserSessionEvent::EVENT_SELECTED,
            $browserSessionRequest->actAction === 'press' => BrowserSessionEvent::EVENT_KEY_PRESSED,
            $browserSessionRequest->actAction === 'click' => BrowserSessionEvent::EVENT_CLICKED,
            default => null,
        };
        if ($eventType === BrowserSessionEvent::EVENT_TAB_CHANGED && $browserSessionRequest->tabAction === 'list') {
            return;
        }
        if ($eventType === null && $browserSessionAnswer->navigated !== true) {
            return;
        }
        $detailParts = [];
        if ($browserSessionAnswer->redirects !== []) {
            $detailParts[] = 'redirects: ' . implode(' → ', $browserSessionAnswer->redirects);
        }
        if ($browserSessionRequest->text !== null) {
            $detailParts[] = 'typed: "' . $browserSessionRequest->text . '" into ' . $browserSessionRequest->ref;
        } elseif ($browserSessionRequest->ref !== null) {
            $detailParts[] = 'ref ' . $browserSessionRequest->ref;
        }
        if ($browserSessionRequest->values !== null) {
            $detailParts[] = 'selected: ' . implode(', ', $browserSessionRequest->values);
        }
        if ($browserSessionRequest->releaseToken !== null) {
            $detailParts[] = 'released submission';
        }
        $browserSessionsService->recordEvent(
            $browserSession,
            $eventType ?? BrowserSessionEvent::EVENT_NAVIGATED,
            $toolName,
            $browserSessionAnswer->finalUrl ?? $browserSessionRequest->url,
            $detailParts === [] ? null : implode('; ', $detailParts)
        );
    }

    protected function findLoadedMessagesOfCall(?AgentToolCallProvenance $callProvenance): ?AIConversationMessages
    {
        if ($callProvenance?->conversationMessages !== null) {
            return $callProvenance->conversationMessages;
        }
        $conversation = $callProvenance?->conversation;
        return $conversation instanceof AIConversation && isset($conversation->messages) ? $conversation->messages : null;
    }

    protected function getBrowserAccessService(): BrowserAccessService
    {
        /** @var BrowserAccessService $browserAccessService */
        $browserAccessService = DDDService::instance()->getService(BrowserAccessService::class);
        return $browserAccessService;
    }

    protected function getBrowserSessionsService(): BrowserSessionsService
    {
        /** @var BrowserSessionsService $browserSessionsService */
        $browserSessionsService = DDDService::instance()->getService(BrowserSessionsService::class);
        return $browserSessionsService;
    }

    protected function getBrowserRendererService(): BrowserRendererService
    {
        /** @var BrowserRendererService $browserRendererService */
        $browserRendererService = DDDService::instance()->getService(BrowserRendererService::class);
        return $browserRendererService;
    }

    protected function getBrowserOriginRulesService(): BrowserOriginRulesService
    {
        /** @var BrowserOriginRulesService $browserOriginRulesService */
        $browserOriginRulesService = DDDService::instance()->getService(BrowserOriginRulesService::class);
        return $browserOriginRulesService;
    }

    protected function getOwnerConsentsService(): OwnerConsentsService
    {
        /** @var OwnerConsentsService $ownerConsentsService */
        $ownerConsentsService = DDDService::instance()->getService(OwnerConsentsService::class);
        return $ownerConsentsService;
    }
}
