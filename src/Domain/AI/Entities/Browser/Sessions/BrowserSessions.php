<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\AI\Repo\DB\Browser\Sessions\DBBrowserSessions;
use DDD\Domain\AI\Services\Browser\BrowserSessionsService;

/**
 * @property BrowserSession[] $elements
 * @method BrowserSession|null getByUniqueKey(string $uniqueKey)
 * @method BrowserSession[] getElements()
 * @method BrowserSession|null first()
 * @method static BrowserSessionsService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBBrowserSessions::class)]
class BrowserSessions extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = BrowserSessionsService::class;
}
