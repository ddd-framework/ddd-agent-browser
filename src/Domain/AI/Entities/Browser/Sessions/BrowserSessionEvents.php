<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Sessions;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\AI\Repo\DB\Browser\Sessions\DBBrowserSessionEvents;
use DDD\Domain\AI\Services\Browser\BrowserSessionsService;

/**
 * @property BrowserSessionEvent[] $elements
 * @method BrowserSessionEvent|null getByUniqueKey(string $uniqueKey)
 * @method BrowserSessionEvent[] getElements()
 * @method BrowserSessionEvent|null first()
 * @method static BrowserSessionsService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBBrowserSessionEvents::class)]
class BrowserSessionEvents extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = BrowserSessionsService::class;
}
