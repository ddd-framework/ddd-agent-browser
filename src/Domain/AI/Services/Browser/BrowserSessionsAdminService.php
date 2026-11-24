<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Browser;

use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererContract;
use DDD\Domain\AI\Entities\Browser\Renderer\BrowserRendererDeploymentChecks;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionEvent;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionEvents;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionRequest;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessions;
use DDD\Domain\AI\Repo\DB\Browser\Sessions\DBBrowserSession;
use DDD\Domain\AI\Repo\DB\Browser\Sessions\DBBrowserSessions;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The admin side of the browser sessions (`BrowserSessionsControllerTrait`): the sessions, newest first, filtered by
 * status, conversation (the root of a delegation tree) or account; one session and its log; closing a session by an
 * operator (the renderer's context is closed, the row ends, the log names the operator's reason); the check of the
 * renderer deployments. Reads run with the row rights of `DBBrowserSession` (an admin reads every session).
 */
class BrowserSessionsAdminService extends Service
{
    /** @var int Sessions of one page by default */
    public const int DEFAULT_SESSIONS_LIMIT = 50;

    /** @var int Sessions of one page at most */
    public const int MAX_SESSIONS_LIMIT = 200;

    /** @var int Log entries of one session by default */
    public const int DEFAULT_EVENTS_LIMIT = 500;

    /** @var string The tool name of a log entry an operator caused */
    public const string OPERATOR_TOOL_NAME = 'admin';

    /** @var int Characters of an operator's reason kept in the log */
    public const int MAX_REASON_CHARACTERS = 500;

    /**
     * The sessions, newest first; a filter that is null does not narrow. Asks one more than the limit, so the caller
     * learns whether a further page exists ({@see BrowserSessionsAdminService::hasMoreThan()}).
     * @param string|null $status One of BrowserSession::STATUS_*
     * @param int|null $conversationId
     * @param int|null $accountId
     * @param int $limit
     * @param int $offset
     * @return BrowserSessions
     */
    public function findSessions(?string $status, ?int $conversationId, ?int $accountId, int $limit, int $offset): BrowserSessions
    {
        $repoClass = DBBrowserSessions::class;
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = DBBrowserSession::getBaseModelAlias();
        if ($status !== null && in_array($status, [BrowserSession::STATUS_OPEN, BrowserSession::STATUS_CLOSED, BrowserSession::STATUS_GONE], true)) {
            $queryBuilder->andWhere("{$baseModelAlias}.status = :browserSessionStatus")->setParameter('browserSessionStatus', $status);
        }
        if ($conversationId !== null) {
            $queryBuilder->andWhere("{$baseModelAlias}.conversationId = :browserSessionConversationId")->setParameter('browserSessionConversationId', $conversationId);
        }
        if ($accountId !== null) {
            $queryBuilder->andWhere("{$baseModelAlias}.accountId = :browserSessionAccountId")->setParameter('browserSessionAccountId', $accountId);
        }
        $queryBuilder->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($this->normalizeSessionsLimit($limit) + 1);
        return (new DBBrowserSessions())->find($queryBuilder);
    }

    /**
     * Whether a page asked with {@see findSessions()} holds more than the limit; the extra session is removed.
     * @param BrowserSessions $browserSessions
     * @param int $limit
     * @return bool
     */
    public function hasMoreThan(BrowserSessions $browserSessions, int $limit): bool
    {
        $normalizedLimit = $this->normalizeSessionsLimit($limit);
        $elements = array_values($browserSessions->getElements());
        if (count($elements) <= $normalizedLimit) {
            return false;
        }
        $browserSessions->remove($elements[count($elements) - 1]);
        return true;
    }

    public function normalizeSessionsLimit(int $limit): int
    {
        return max(1, min($limit > 0 ? $limit : static::DEFAULT_SESSIONS_LIMIT, static::MAX_SESSIONS_LIMIT));
    }

    /**
     * One session, read with the row rights.
     * @param int $browserSessionId
     * @return BrowserSession
     * @throws NotFoundException
     */
    public function findSession(int $browserSessionId): BrowserSession
    {
        $browserSession = $this->getBrowserSessionsService()->find($browserSessionId);
        if (!$browserSession instanceof BrowserSession) {
            throw new NotFoundException('No browser session ' . $browserSessionId);
        }
        return $browserSession;
    }

    /**
     * The log of a session, oldest first.
     * @param BrowserSession $browserSession
     * @param int|null $limit
     * @return BrowserSessionEvents
     */
    public function findEventsOfSession(BrowserSession $browserSession, ?int $limit): BrowserSessionEvents
    {
        return $this->getBrowserSessionsService()->findEventsOfSession($browserSession, $limit ?? static::DEFAULT_EVENTS_LIMIT);
    }

    /**
     * Closes an open session for an operator: the renderer closes the context (when it holds one and is reachable),
     * the row ends as CLOSED, the log names the operator's reason and what the renderer answered. The agent's next
     * navigation opens a new session.
     * @param BrowserSession $browserSession
     * @param string|null $reason
     * @return BrowserSession
     * @throws ConflictException When the session is no longer open
     */
    public function closeSession(BrowserSession $browserSession, ?string $reason): BrowserSession
    {
        if (!$browserSession->isOpen()) {
            throw new ConflictException('The browser session is already ' . strtolower((string)$browserSession->status));
        }
        $rendererOutcome = 'the renderer held no context';
        if ($browserSession->sessionKey !== null) {
            $browserSessionAnswer = $this->getBrowserRendererService()->runSessionAction(
                BrowserSessionRequest::forAction(BrowserRendererContract::SESSION_ACTION_CLOSE, $browserSession->sessionKey)
            );
            if ($browserSessionAnswer->isRendererFault()) {
                $rendererOutcome = 'the renderer could not be reached (' . ($browserSessionAnswer->error ?? 'HTTP ' . ($browserSessionAnswer->httpStatusCode ?? '?')) . '); it ends the context after its idle time';
            } elseif ($browserSessionAnswer->reason === BrowserRendererContract::REASON_SESSION_GONE) {
                $rendererOutcome = 'the renderer no longer held the context';
            } else {
                $rendererOutcome = 'the renderer closed the context';
            }
        }
        $browserSession->status = BrowserSession::STATUS_CLOSED;
        $browserSession->closedAt = new DateTime();
        $browserSessionsService = $this->getBrowserSessionsService();
        $browserSession = $browserSessionsService->saveSession($browserSession);
        $trimmedReason = $reason !== null ? trim($reason) : '';
        $detail = 'closed by an operator'
            . ($trimmedReason !== '' ? ': ' . mb_substr($trimmedReason, 0, static::MAX_REASON_CHARACTERS) : '')
            . '; ' . $rendererOutcome;
        $browserSessionsService->recordEvent($browserSession, BrowserSessionEvent::EVENT_CLOSED, static::OPERATOR_TOOL_NAME, $browserSession->currentUrl, $detail);
        return $browserSession;
    }

    /**
     * Checks the configured renderer deployments against this package (the same check as
     * `app:agent-browser:check-renderer`).
     * @return BrowserRendererDeploymentChecks
     */
    public function checkRendererDeployments(): BrowserRendererDeploymentChecks
    {
        return $this->getBrowserRendererService()->checkDeployments();
    }

    /** Whether interactive sessions are switched on (`AI.Browser.sessions.enabled`) */
    public function areSessionsEnabled(): bool
    {
        return $this->getBrowserAccessService()->areSessionsEnabled();
    }

    /** Whether a deployment for the session routes is configured */
    public function isSessionEndpointConfigured(): bool
    {
        return $this->getBrowserRendererService()->isSessionEndpointConfigured();
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

    protected function getBrowserAccessService(): BrowserAccessService
    {
        /** @var BrowserAccessService $browserAccessService */
        $browserAccessService = DDDService::instance()->getService(BrowserAccessService::class);
        return $browserAccessService;
    }
}
