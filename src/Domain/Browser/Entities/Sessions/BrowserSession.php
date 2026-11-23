<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Entities\Sessions;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Browser\Repo\DB\Sessions\DBBrowserSession;
use DDD\Domain\Browser\Services\BrowserSessionsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One interactive browser session: one fresh browser context on the renderer's sessions deployment for one
 * conversation (the root of a delegation tree). The renderer creates the random `sessionKey` on the first navigation
 * and files it under the authenticated caller class; this row keeps it, the current page and the counters. Its
 * events are the session's log (navigations, consents, refusals).
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBBrowserSession::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['conversationId', 'status'])]
class BrowserSession extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = BrowserSessionsService::class;

    /** The renderer holds the context */
    public const string STATUS_OPEN = 'OPEN';

    /** Closed by a tool or by the platform */
    public const string STATUS_CLOSED = 'CLOSED';

    /** The renderer answered SESSION_GONE (idle close, limit, restart): the next navigation opens a new context */
    public const string STATUS_GONE = 'GONE';

    /** @var int|null The conversation (the root of its delegation tree) that drives the session */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $conversationId = null;

    /** @var int|null The agent space of the conversation */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $agentSpaceId = null;

    /** @var int|null The account the session runs for (the conversation's owner or the signed-in account) */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $accountId = null;

    /** @var string|null The renderer's random key of the context; null before the first navigation answered */
    #[Length(max: 128)]
    public ?string $sessionKey = null;

    #[NotNull]
    #[Length(max: 16)]
    #[Choice(choices: [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_GONE])]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $status = self::STATUS_OPEN;

    /** @var string|null The URL of the active tab after the last action */
    #[Length(max: 2048)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $currentUrl = null;

    /** @var string|null The title of the active tab after the last action (third-party text) */
    #[Length(max: 512)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $currentTitle = null;

    /** @var int|null Main-frame navigations so far (the renderer ends a session after its limit) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $navigationCount = 0;

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?DateTime $lastActionAt = null;

    public ?DateTime $closedAt = null;

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
