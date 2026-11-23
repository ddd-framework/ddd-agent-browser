<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Browser\Entities\Renderer\BrowserRendererContract;
use DDD\Domain\Browser\Entities\Sessions\BrowserSession;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionAnswer;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionEvent;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionEvents;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessions;
use DDD\Domain\Browser\Repo\DB\Sessions\DBBrowserSession;
use DDD\Domain\Browser\Repo\DB\Sessions\DBBrowserSessionEvent;
use DDD\Domain\Browser\Repo\DB\Sessions\DBBrowserSessionEvents;
use DDD\Domain\Browser\Repo\DB\Sessions\DBBrowserSessions;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;

/**
 * The sessions of conversations and their log: the open session of a conversation, the renderer's answers applied to
 * it, the log entries, and the purge after the retention time. The lookups and writes for a conversation run without
 * row rights (the conversation scopes them; the tools check who may browse); the row rights govern the log's readers.
 *
 * @method BrowserSession find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method BrowserSessions findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBBrowserSession getEntityRepoClassInstance()
 * @method DBBrowserSessions getEntitySetRepoClassInstance()
 */
class BrowserSessionsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = BrowserSession::class;

    /** @var int Characters of a log entry's detail */
    public const int MAX_EVENT_DETAIL_CHARACTERS = 4000;

    /**
     * The open session of a conversation; null when it has none.
     * @param int $conversationId
     * @return BrowserSession|null
     */
    public function findOpenSessionOfConversation(int $conversationId): ?BrowserSession
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.conversationId = :conversationId")
            ->andWhere("{$baseModelAlias}.status = :status")
            ->setParameter('conversationId', $conversationId)
            ->setParameter('status', BrowserSession::STATUS_OPEN)
            ->orderBy("{$baseModelAlias}.id", 'DESC')
            ->setMaxResults(1);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $repoClass->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The conversation's open session, or a new one (without a renderer key until the first navigation answered).
     * @param int $conversationId
     * @param int|null $agentSpaceId
     * @param int|null $accountId
     * @return BrowserSession
     */
    public function findOrStartSessionOfConversation(int $conversationId, ?int $agentSpaceId, ?int $accountId): BrowserSession
    {
        $browserSession = $this->findOpenSessionOfConversation($conversationId);
        if ($browserSession !== null) {
            return $browserSession;
        }
        $browserSession = BrowserSession::newInstance();
        $browserSession->conversationId = $conversationId;
        $browserSession->agentSpaceId = $agentSpaceId;
        $browserSession->accountId = $accountId;
        $browserSession->status = BrowserSession::STATUS_OPEN;
        $browserSession->navigationCount = 0;
        return $this->saveSession($browserSession);
    }

    /**
     * Takes over what an answer says about the session: its key, the page, the navigation count; `SESSION_GONE` ends it.
     * @param BrowserSession $browserSession
     * @param BrowserSessionAnswer $browserSessionAnswer
     * @return BrowserSession
     */
    public function applyAnswerToSession(BrowserSession $browserSession, BrowserSessionAnswer $browserSessionAnswer): BrowserSession
    {
        if ($browserSessionAnswer->isRendererFault()) {
            return $browserSession;
        }
        if ($browserSessionAnswer->reason === BrowserRendererContract::REASON_SESSION_GONE) {
            $browserSession->status = BrowserSession::STATUS_GONE;
            $browserSession->closedAt = new DateTime();
            $browserSession = $this->saveSession($browserSession);
            $this->recordEvent($browserSession, BrowserSessionEvent::EVENT_GONE, null, null, 'the renderer no longer holds the session');
            return $browserSession;
        }
        if ($browserSessionAnswer->reason === BrowserRendererContract::REASON_CLOSED) {
            $browserSession->status = BrowserSession::STATUS_CLOSED;
            $browserSession->closedAt = new DateTime();
        }
        if ($browserSessionAnswer->sessionKey !== null) {
            $browserSession->sessionKey = $browserSessionAnswer->sessionKey;
        }
        if ($browserSessionAnswer->finalUrl !== null) {
            $browserSession->currentUrl = mb_substr($browserSessionAnswer->finalUrl, 0, 2048);
        }
        if ($browserSessionAnswer->title !== null) {
            $browserSession->currentTitle = mb_substr($browserSessionAnswer->title, 0, 512);
        }
        if ($browserSessionAnswer->action === BrowserRendererContract::SESSION_ACTION_NAVIGATE || $browserSessionAnswer->navigated === true) {
            $browserSession->navigationCount = (int)$browserSession->navigationCount + 1;
        }
        $browserSession->lastActionAt = new DateTime();
        return $this->saveSession($browserSession);
    }

    /**
     * Writes one entry of the session's log.
     * @param BrowserSession $browserSession
     * @param string $eventType
     * @param string|null $toolName
     * @param string|null $url
     * @param string|null $detail
     * @return void
     */
    public function recordEvent(BrowserSession $browserSession, string $eventType, ?string $toolName, ?string $url, ?string $detail): void
    {
        if (!isset($browserSession->id)) {
            return;
        }
        $browserSessionEvent = BrowserSessionEvent::newInstance();
        $browserSessionEvent->browserSessionId = (int)$browserSession->id;
        $browserSessionEvent->eventType = $eventType;
        $browserSessionEvent->toolName = $toolName;
        $browserSessionEvent->url = $url !== null ? mb_substr($url, 0, 2048) : null;
        $browserSessionEvent->detail = $detail !== null ? mb_substr($detail, 0, static::MAX_EVENT_DETAIL_CHARACTERS) : null;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $browserSessionEvent->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The log of a session, oldest first.
     * @param BrowserSession $browserSession
     * @param int $limit
     * @return BrowserSessionEvents
     */
    public function findEventsOfSession(BrowserSession $browserSession, int $limit = 500): BrowserSessionEvents
    {
        $repoClass = DBBrowserSessionEvents::class;
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = DBBrowserSessionEvent::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.browserSessionId = :browserSessionId")
            ->setParameter('browserSessionId', (int)$browserSession->id)
            ->orderBy("{$baseModelAlias}.id", 'ASC')
            ->setMaxResults(max(1, min($limit, 2000)));
        return (new DBBrowserSessionEvents())->find($queryBuilder);
    }

    /**
     * Writes a session as the platform's bookkeeping: the tools that drive it already checked who may browse, and the
     * conversation scopes it, so the row rights (which govern reading the log) do not apply to the write.
     * @param BrowserSession $browserSession
     * @return BrowserSession
     */
    public function saveSession(BrowserSession $browserSession): BrowserSession
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $browserSession->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Ends a session on our side (after the renderer closed it, or when the renderer cannot be reached).
     * @param BrowserSession $browserSession
     * @param string|null $toolName
     * @return BrowserSession
     */
    public function markSessionClosed(BrowserSession $browserSession, ?string $toolName): BrowserSession
    {
        $browserSession->status = BrowserSession::STATUS_CLOSED;
        $browserSession->closedAt = new DateTime();
        $browserSession = $this->saveSession($browserSession);
        $this->recordEvent($browserSession, BrowserSessionEvent::EVENT_CLOSED, $toolName, $browserSession->currentUrl, null);
        return $browserSession;
    }

    /**
     * Deletes sessions that ended (or were last used) before the retention time, with their log. `retentionDays` 0
     * keeps them without a time limit.
     * @param bool $isDryRun
     * @return int the sessions deleted (or that would be)
     */
    public function purgeExpiredSessions(bool $isDryRun = false): int
    {
        $retentionDays = (int)(Config::get('Browser.sessions.retentionDays') ?? 0);
        if ($retentionDays <= 0) {
            return 0;
        }
        $cutoff = (new DateTime())->modify('-' . $retentionDays . ' days');
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $repoClass = $this->getEntitySetRepoClassInstance();
            $queryBuilder = $repoClass::createQueryBuilder();
            $baseModelAlias = $repoClass::getBaseModelAlias();
            $queryBuilder->andWhere("({$baseModelAlias}.lastActionAt IS NULL AND {$baseModelAlias}.created < :cutoff) OR {$baseModelAlias}.lastActionAt < :cutoff")
                ->setParameter('cutoff', $cutoff)
                ->setMaxResults(500);
            $expiredSessions = $repoClass->find($queryBuilder);
            if ($isDryRun) {
                return $expiredSessions->count();
            }
            $deletedCount = 0;
            foreach ($expiredSessions->getElements() as $expiredSession) {
                foreach ($this->findEventsOfSession($expiredSession, 2000)->getElements() as $browserSessionEvent) {
                    $browserSessionEvent->delete();
                }
                $expiredSession->delete();
                $deletedCount++;
            }
            return $deletedCount;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }
}
