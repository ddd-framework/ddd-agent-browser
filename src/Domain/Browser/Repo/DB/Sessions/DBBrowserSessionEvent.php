<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Repo\DB\Sessions;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Browser\Entities\Sessions\BrowserSessionEvent;
use DDD\Infrastructure\Services\AuthService;

/**
 * Rights through the session: an admin reads every entry; any other account only the entries of the sessions it runs.
 *
 * @method BrowserSessionEvent find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method BrowserSessionEvent update(DefaultObject &$entity, int $depth = 1)
 */
class DBBrowserSessionEvent extends DBEntity
{
    public const string BASE_ENTITY_CLASS = BrowserSessionEvent::class;
    public const string BASE_ORM_MODEL = DBBrowserSessionEventModel::class;

    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $authAccount = AuthService::instance()->getAccount();
        if ($authAccount !== null && ($authAccount->roles?->isAdmin() ?? false)) {
            return false;
        }
        $baseModelAlias = static::getBaseModelAlias();
        $sessionModelClass = DBBrowserSessionModel::class;
        $queryBuilder->andWhere(
            "{$baseModelAlias}.browserSessionId IN (SELECT {$baseModelAlias}_rightsSession.id FROM {$sessionModelClass} {$baseModelAlias}_rightsSession"
            . " WHERE {$baseModelAlias}_rightsSession.accountId = :browserSessionEventRightsAccountId)"
        )->setParameter('browserSessionEventRightsAccountId', (int)($authAccount?->id ?? 0));
        return true;
    }
}
