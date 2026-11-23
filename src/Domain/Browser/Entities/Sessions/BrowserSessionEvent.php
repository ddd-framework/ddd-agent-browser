<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Entities\Sessions;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Browser\Repo\DB\Sessions\DBBrowserSessionEvent;
use DDD\Domain\Browser\Services\BrowserSessionsService;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One entry of a session's log: every main-frame navigation (with its redirect target), every typed text, consent
 * questions and releases, refusals and warnings. The admin screen reads it; it is purged with its session.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBBrowserSessionEvent::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX, indexColumns: ['browserSessionId', 'id'])]
class BrowserSessionEvent extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = BrowserSessionsService::class;

    public const string EVENT_NAVIGATED = 'NAVIGATED';
    public const string EVENT_CLICKED = 'CLICKED';
    public const string EVENT_TYPED = 'TYPED';
    public const string EVENT_SELECTED = 'SELECTED';
    public const string EVENT_KEY_PRESSED = 'KEY_PRESSED';
    public const string EVENT_TAB_CHANGED = 'TAB_CHANGED';
    public const string EVENT_CONSENT_REQUIRED = 'CONSENT_REQUIRED';
    public const string EVENT_SUBMISSION_RELEASED = 'SUBMISSION_RELEASED';
    public const string EVENT_REFUSED = 'REFUSED';
    public const string EVENT_ASSET_STORED = 'ASSET_STORED';
    public const string EVENT_CLOSED = 'CLOSED';
    public const string EVENT_GONE = 'GONE';

    #[NotNull]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BIGINT)]
    public ?int $browserSessionId = null;

    #[NotNull]
    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $eventType = null;

    /** @var string|null The tool that caused the entry */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $toolName = null;

    /** @var string|null The URL the entry is about (the final URL of a navigation, the target of a submission) */
    #[Length(max: 2048)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $url = null;

    /** @var string|null What happened (redirect hops, typed text, refusal reason); third-party text included */
    #[Length(max: 4000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $detail = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
