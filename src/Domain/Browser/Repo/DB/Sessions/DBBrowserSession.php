<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Repo\DB\Sessions;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Browser\Entities\Sessions\BrowserSession;
use DDD\Infrastructure\Services\AuthService;

/**
 * Rights: an admin reads and writes every session; any other account only the sessions it runs. The tools write as
 * the conversation's account; system jobs (the purge) switch the rights off for their own call.
 *
 * @method BrowserSession find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method BrowserSession update(DefaultObject &$entity, int $depth = 1)
 */
class DBBrowserSession extends DBEntity
{
    public const string BASE_ENTITY_CLASS = BrowserSession::class;
    public const string BASE_ORM_MODEL = DBBrowserSessionModel::class;

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
        $queryBuilder->andWhere("{$baseModelAlias}.accountId = :browserSessionRightsAccountId")
            ->setParameter('browserSessionRightsAccountId', (int)($authAccount?->id ?? 0));
        return true;
    }
}
