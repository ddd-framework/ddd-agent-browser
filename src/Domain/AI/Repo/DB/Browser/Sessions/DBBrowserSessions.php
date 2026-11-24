<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Browser\Sessions;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSessions;

/**
 * @method BrowserSessions find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBBrowserSessions extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBBrowserSession::class;
    public const string BASE_ENTITY_SET_CLASS = BrowserSessions::class;
}
