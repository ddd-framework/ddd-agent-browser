<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Browser\Sessions;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessionEvents;

/**
 * @method BrowserSessionEvents find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBBrowserSessionEvents extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBBrowserSessionEvent::class;
    public const string BASE_ENTITY_SET_CLASS = BrowserSessionEvents::class;
}
